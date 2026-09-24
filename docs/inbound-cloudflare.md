# Receiving mail with Cloudflare Email Routing

Cloudflare Email Routing accepts mail for your domain for free and can hand each message to a small Worker. The Worker below posts the message to your Grav site, byte for byte, signed with a secret only the Worker and the site know. The Email plugin's built-in `cloudflare` receiver checks the signature and reads the message. It works whichever provider your site sends mail through, and needs no mail server of your own.

The same signed format is accepted by the `generic` receiver, for anything you write yourself. There's a shell and a PHP sender for that at the end of this page.

## What Cloudflare gives the Worker

These are from Cloudflare's runtime API documentation ([Email Workers runtime API](https://developers.cloudflare.com/email-routing/email-workers/runtime-api/), [Email handler](https://developers.cloudflare.com/email-service/api/route-emails/email-handler/)), checked on 2026-09-23:

- `message.raw` is a `ReadableStream` of the whole message as received. `new Response(message.raw).arrayBuffer()` reads it into bytes.
- `message.to` is the envelope recipient (SMTP `RCPT TO`) and `message.from` the envelope sender (SMTP `MAIL FROM`), as strings. A bounce has an empty sender.
- `message.rawSize` is the size in bytes.
- `message.forward(address)` sends it on to a verified destination address. `message.setReject(reason)` refuses it with a permanent SMTP error, so the sender gets a bounce.

## Plus addresses

Replies to your site's mail come back to addresses like `support+t8f2k@example.com`, and the part after the `+` is how the site finds the right conversation. Cloudflare Email Routing supports this (RFC 5233 subaddressing). It's a setting you have to turn on: **Email Routing > Settings > Subaddressing**. Once it's on, `support+anything@example.com` is matched by your `support@example.com` rule, and the full address, `+detail` included, is kept in `message.to` ([Email routing rules and addresses](https://developers.cloudflare.com/email-service/configuration/email-routing-addresses/#subaddressing)). The Worker sends that address as `X-Grav-Envelope-To`, so the token arrives even when the visible `To:` header says just `support@`.

A catch-all rule (every address at the domain) also works. When both exist, a rule for the exact subaddress wins over the rule for the base address.

## Limits

- **Message size:** Email Routing accepts inbound messages up to 25 MiB ([limits](https://developers.cloudflare.com/email-routing/limits/)). The `cloudflare` receiver's own limit is the same. Your site can set a lower one.
- **Worker CPU time:** 10 ms per invocation on the Workers Free plan, 30 seconds by default on Paid ([Workers limits](https://developers.cloudflare.com/workers/platform/limits/)). Cloudflare's email docs warn that complex email handlers can go over the Free plan's limits and show up as `EXCEEDED_CPU` in the logs. This Worker does one HMAC pass and one `fetch`, with no parsing, so ordinary mail fits. If you see `EXCEEDED_CPU` on messages with large attachments, move the Worker to the Paid plan.
- **Memory:** 128 MB per Worker, well above a 25 MiB message held twice.
- **Time to answer:** the Worker waits up to 25 seconds for your site. Cloudflare doesn't document a wall-clock limit for email handlers. A site that stores the message and answers straight away (as Helpdesk Pro does) answers in well under a second.

## Set it up

1. In the Cloudflare dashboard, open your domain and enable **Email Routing**. Cloudflare adds the MX and SPF records it needs. If the domain already receives mail somewhere else, use a subdomain (for example `help.example.com`) so you don't move your existing mailboxes.
2. Under **Email Routing > Settings**, turn on **Subaddressing**.
3. Create a Worker (**Workers & Pages > Create > Worker**), replace its code with the source below, and deploy it.
4. In the Worker's **Settings > Variables and Secrets**, add two secrets: `WEBHOOK_URL`, set to the inbound address your site shows (it ends in `/cloudflare/` and a long secret), and `SIGNING_SECRET`, set to the signing secret your site shows. You can add `FALLBACK_TO` too (see below).
5. Back in **Email Routing > Routing rules**, create a rule for your support address with the action **Send to a Worker**, and pick the Worker.
6. Send a test message to the support address and check that it arrives on the site.

With wrangler instead of the dashboard, save the source as `src/index.js` and run `npx wrangler secret put WEBHOOK_URL` and `npx wrangler secret put SIGNING_SECRET` before `npx wrangler deploy`.

## The Worker

No dependencies; paste it into the dashboard editor as it is.

```js
/**
 * Cloudflare Email Worker for the Grav Email plugin's `cloudflare` receiver.
 *
 * Posts every message Email Routing hands it to your site, byte for byte,
 * signed with a secret only the Worker and the site know.
 *
 * Secrets (Settings > Variables and Secrets, or `npx wrangler secret put NAME`):
 *   WEBHOOK_URL     the inbound address your site shows, e.g.
 *                   https://example.com/_helpdesk/inbound/cloudflare/<url secret>
 *   SIGNING_SECRET  the signing secret your site shows (32+ characters)
 * Optional:
 *   FALLBACK_TO     a verified Email Routing destination address that gets the
 *                   message when the site cannot take it, so nothing is lost
 */
export default {
  async email(message, env, ctx) {
    const raw = new Uint8Array(await new Response(message.raw).arrayBuffer());
    const t = Math.floor(Date.now() / 1000).toString();

    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
      'raw',
      encoder.encode(env.SIGNING_SECRET),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign'],
    );
    const prefix = encoder.encode(t + '.');
    const signed = new Uint8Array(prefix.length + raw.length);
    signed.set(prefix);
    signed.set(raw, prefix.length);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, signed));
    const v1 = Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');

    let status = 0;
    try {
      const response = await fetch(env.WEBHOOK_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'message/rfc822',
          'X-Grav-Envelope-To': message.to,
          'X-Grav-Envelope-From': message.from,
          'X-Grav-Signature': `t=${t},v1=${v1}`,
        },
        body: raw,
        signal: AbortSignal.timeout(25000),
      });
      status = response.status;
    } catch (error) {
      console.error('Could not reach the site:', error);
    }

    if (status >= 200 && status < 300) {
      return;
    }

    console.error(`The site answered ${status || 'nothing'} for a message to ${message.to}`);
    if (env.FALLBACK_TO) {
      await message.forward(env.FALLBACK_TO);
      return;
    }
    message.setReject('The support mailbox could not accept this message right now. Please try again later.');
  },
};
```

### When the site can't take a message

If your site is down, or answers anything other than 2xx, the Worker writes the reason to the Worker's logs and then:

- forwards the message to `FALLBACK_TO` when you've set it. That must be a destination address you've verified in Email Routing (a person's mailbox, for example), so nothing is lost and someone can resend it later;
- otherwise refuses it with `setReject()`, which Cloudflare turns into a permanent SMTP error, so the sender gets a bounce and knows it didn't arrive.

Cloudflare doesn't document whether an exception thrown from an email handler becomes a temporary SMTP failure (one the sending server retries) or a permanent one. The Worker never relies on that. Posting the same message twice is safe: a consumer such as Helpdesk Pro drops the second copy.

## The signed request

This is what the Worker sends, and what the `generic` receiver accepts from anything else:

```
POST {inbound address}
Content-Type: message/rfc822
X-Grav-Envelope-To: support+t8f2k@example.com
X-Grav-Envelope-From: customer@example.net
X-Grav-Signature: t=1758650000,v1=5f2b…

{the raw message, byte for byte}
```

- `t` is the Unix time the request was signed.
- `v1` is the lower-case hex HMAC-SHA256 of `t`, a full stop, and the body, keyed with the signing secret. The body is the exact bytes sent, with no re-encoding and no line-ending changes.
- The site refuses a request whose `t` is more than 300 seconds from its own clock, so a captured request can't be replayed later. Keep both clocks on NTP.
- Several `v1=` values may be sent in one header, and any match passes. That lets you rotate the secret: sign with both for a while, then drop the old one.
- `X-Grav-Envelope-To` may list several recipients, comma separated. An empty `X-Grav-Envelope-From` means the null sender (a bounce). Leave the header out when you don't know the sender, and the message's own `Return-Path` is used.
- The envelope headers are not part of the signature. The request travels over HTTPS, and a replay inside the 300-second window still needs the exact signed body.

The signing secret must be at least 32 characters. A shorter one is refused as unconfigured rather than accepted as weak. Generate one with `openssl rand -hex 32`.

The site's own config keys for these receivers are:

| Key | Meaning |
|---|---|
| `secret` | the signing secret (required, at least 32 characters) |
| `tolerance` | seconds of clock difference allowed (default 300) |
| `max_bytes` | a size limit lower than the receiver's 25 MiB (optional) |

## A generic sender

For a Postfix or Exim pipe, a forwarding script, or a cron job reading a Maildir. It reads the message from a file or from stdin, signs it, and posts it. It exits with 75 (`EX_TEMPFAIL`) when the site doesn't take the message, so an MTA pipe keeps the message and tries again later.

```sh
#!/bin/sh
# Sign one raw message and post it to a Grav site's `generic` inbound receiver.
#
#   grav-inbound.sh RECIPIENT SENDER [FILE]      (the message on stdin when FILE is left out)
#
# Exits 75 (EX_TEMPFAIL) when the site does not take it, so an MTA pipe retries.

URL="${GRAV_INBOUND_URL:?set GRAV_INBOUND_URL to the inbound address your site shows}"
SECRET="${GRAV_INBOUND_SECRET:?set GRAV_INBOUND_SECRET to the signing secret your site shows}"
RECIPIENT="$1"
SENDER="$2"

FILE="$(mktemp)" || exit 75
trap 'rm -f "$FILE"' EXIT
cat "${3:--}" > "$FILE" || exit 75

T="$(date +%s)"
SIG="$( { printf '%s.' "$T"; cat "$FILE"; } | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"

curl --silent --show-error --fail --max-time 30 \
  -H 'Content-Type: message/rfc822' \
  -H "X-Grav-Envelope-To: $RECIPIENT" \
  -H "X-Grav-Envelope-From: $SENDER" \
  -H "X-Grav-Signature: t=$T,v1=$SIG" \
  --data-binary @"$FILE" \
  "$URL" > /dev/null || exit 75
```

Try it by hand:

```sh
export GRAV_INBOUND_URL='https://example.com/_helpdesk/inbound/generic/<url secret>'
export GRAV_INBOUND_SECRET='<signing secret>'
./grav-inbound.sh support+test@example.com you@example.net message.eml
```

As a Postfix transport, in `master.cf` (with the two variables set in the script itself, since Postfix's pipe does not pass the environment through):

```
grav      unix  -       n       n       -       -       pipe
  flags=Rq user=nobody argv=/usr/local/bin/grav-inbound.sh ${recipient} ${sender}
```

The same thing in PHP:

```php
<?php
$url    = 'https://example.com/_helpdesk/inbound/generic/<url secret>';
$secret = '<signing secret>';
$raw    = file_get_contents($argv[1] ?? 'php://stdin');
$time   = time();

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: message/rfc822',
        'X-Grav-Envelope-To: support+test@example.com',
        'X-Grav-Envelope-From: you@example.net',
        'X-Grav-Signature: t=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, $secret),
    ],
]);
curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
exit($status >= 200 && $status < 300 ? 0 : 75);
```

A PHP sender that has the Email plugin loaded can call `Grav\Plugin\Email\Providers\Inbound\Receivers\SignedRawReceiver::sign($raw, $secret)` for the header value.
