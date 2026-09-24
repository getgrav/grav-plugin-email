# The provider contract

Everything a mail provider knows about itself belongs to that provider's own Grav plugin. How its delivery webhooks are verified and read, how a webhook is created from a pasted API key, what a sending domain's DNS has to say, what its transport does to custom headers on the way out — all of it lives in `grav-plugin-email-<provider>`, behind a contract the Email plugin owns. Anything else on the site only consumes.

The reason is simple. Before this, an add-on that wanted to record bounces carried a parser for every provider it supported, which meant adding a provider was editing that add-on. Two add-ons meant two copies of the same parser drifting apart, and a provider that renamed a field in its payload broke both of them silently. Now a provider is a class in the plugin that already talks to that provider, and everything else asks.

## What you write

One class implementing `Grav\Plugin\Email\Providers\Provider`, and one listener that registers it. Nothing else changes in your plugin.

```php
use Grav\Plugin\Email\Providers\ProviderRegistry;
use RocketTheme\Toolbox\Event\Event;

public static function getSubscribedEvents()
{
    return [
        'onEmailEngines'   => ['onEmailEngines', 0],
        'onEmailProviders' => ['onEmailProviders', 0],
    ];
}

public function onEmailProviders(Event $event): void
{
    /** @var ProviderRegistry $providers */
    $providers = $event['providers'];
    $providers->add(new Smtp2goProvider($this->config()));
}
```

`onEmailProviders` is fired once per request, the first time anything asks. A listener that does real work in it — a network call, a database read — is a listener that runs on every admin screen, so build the value object and nothing more.

The registry refuses two providers claiming the same engine, and two claiming the same key, with an exception naming both classes. That is deliberate: a site where two plugins both answer for `ses` has one of them quietly winning on plugin load order, and a merchant would see delivery events verified with the wrong key and nothing anywhere saying why.

## The interface

```php
interface Provider
{
    public function engines(): array;          // ['smtp2go'] — the keys you register on onEmailEngines
    public function key(): string;             // 'smtp2go' — lowercase, addresses your routes and config
    public function label(): string;           // 'SMTP2GO' — a brand name, never translated
    public function capabilities(): Capabilities;
    public function reports(): ?DeliveryReports;   // null when you cannot report deliveries at all
    public function setup(): ?WebhookSetup;        // null when there is no API to create the webhook with
    public function domain(): DomainFacts;
    public function instructions(): string;    // one paragraph, a language key with an English fallback
}
```

`engines()` is a list because a provider that has offered its transport under more than one name over the years should answer for all of them. `key()` is separate from the engine because it is what a route and a config block are addressed by, and only one of those can exist per provider however many engines there are.

### `capabilities()`

Three answers about what your transport does to a message on the way out, and every one of them is a thing that is invisible when it goes wrong.

```php
new Capabilities(
    customHeaders: true,        // an X- header set on the message reaches the wire
    unsubscribeHeaders: true,   // List-Unsubscribe and List-Unsubscribe-Post survive
    echoesHeaders: true,        // the provider hands a registered custom header back in its webhooks
    echoNote: 'Add the send id header to the webhook\'s own header list, or press Set up.',
);
```

A store sets `List-Unsubscribe`, an API transport turns the message into a JSON body and drops every header it does not recognise, the mail goes out looking perfectly fine, and a year later Gmail is filing it as spam because a bulk sender with no unsubscribe button is what a spammer looks like. Nothing on any screen would say so. Answering `unsubscribeHeaders: false` honestly is what lets a screen say so.

`signsWebhooks` is optional and almost always left out: a screen works out whether you sign from `verificationKeys()`, and only a provider that signs with a published certificate and asks the merchant for no key, like SES, needs to say `signsWebhooks: true` itself. `echoNote` is where you say what a merchant has to do for `echoesHeaders` to be true — register the header in a dashboard, or press your setup button — in plain words, because "configure header passthrough" is not instructions.

### `reports()`

Null when the provider has no delivery API at all. There is no null object to write and none to register: a transport with nothing to report simply registers nothing there, and a store says plainly that this transport cannot report deliveries. That sentence is worth far more to a merchant than a webhook address nothing will ever post to.

```php
interface DeliveryReports
{
    public function events(): array;            // from Event::TYPES: what you can report, not what a store ticked
    public function verificationKeys(): array;  // ['signing_key'] — config keys under your own plugin's config
    public function verify(WebhookRequest $request, array $config): Verdict;
    public function parse(WebhookRequest $request): Payload;
    public function sendHeader(): string;       // SendHeader::name(), all but always
}
```

Three rules, and they are not style preferences — each one is a real failure that has happened:

**Authenticate before acting; over raw bytes wherever the scheme signs raw bytes.** `verify()` runs first, before anything is stored, logged as an event or acted on. Where the provider's signature covers the body, check it over `$request->body` before anything has decoded it: a signature checked after the payload was parsed is a signature protecting nothing, since by then a stranger has had your parser walk their JSON, and an HMAC over a body that was decoded and re-encoded on the way will not match however right the key is. Where the signature sits inside the payload (SNS's JSON envelope, Mailgun's form fields), decode only what the check needs, check it, and stop there.

**Never throw from `parse()`.** Truncated JSON, an XML error page from somebody's proxy, an empty body, a documented field that turned out to be a list — all of them are `Payload::unreadable('what went wrong')`. The caller logs the first few hundred bytes and answers 200 anyway, because every one of these providers treats a 4xx as a reason to retry for days and some treat it as a reason to drop the event outright. `parse()` runs on a public address anybody can post to.

**An unrecognised event is skipped, not refused.** Every provider sends more event types than any store acts on — `processed`, `deferred`, `unsubscribed`, `delivery_delayed`. A merchant who ticked every box in your dashboard should get a 200 and a quiet log line, not a refusal. Skip it and carry on with the rest of the batch; `Payload::nothing('an event type this store does not act on')` when the whole batch was skippable.

#### `sendHeader()` answers `SendHeader::name()`

Do not hard-code a name here, and do not invent one of your own. `Grav\Plugin\Email\Providers\SendHeader::name()` is the header a store stamps its send id into, it is `X-Grav-Send-Id` unless the site says otherwise, and the whole point of it living in one place is that the end that writes the header and the end that reads it cannot disagree about it.

```php
public function sendHeader(): string
{
    return SendHeader::name();
}
```

A site changes it with `providers.send_header` in the Email plugin's own configuration, and an add-on that decides at runtime calls `SendHeader::override()`. Either way your provider answers the same string as everything else on the site, and a screen that prints "add this header to your webhook" prints the one that is actually being sent.

There is exactly one reason to answer anything else, and it is Postmark: it echoes no headers on any webhook and hands back `Metadata` instead, so the header that has to go on the message is `SendHeader::metadataHeader()` — the same name under the `X-PM-Metadata-` prefix that is the only way to put something into Postmark's metadata over SMTP. That is a different spelling of the same name rather than a name of your own, and it is still derived from `SendHeader::name()`.

Read it back with the same class, whichever place your provider decided to put echoed headers:

```php
SendHeader::idIn($event['user-variables'] ?? null);   // a map keyed by header name
SendHeader::idInList($mail['headers'] ?? null);       // a {name, value} list
SendHeader::idInTags($mail['tags'] ?? null);          // SES message tags, {name: [value]}
SendHeader::idFrom($row['X-Grav-Send-Id'] ?? null);   // one value you already found
```

All of them answer a string or null, both spellings of the name are tried, and nothing throws. `Event::$sendId` is a string because this plugin has no idea what a store's send id looks like: a store keeping row ids turns it into one at its own end, and a store using a UUID keeps a UUID.

#### A message the provider refused to send

`Event::DROPPED` is the sixth word and it means the provider never handed the message to a receiving server — the address was on the provider's own suppression list, or it decided the message was spam or carried a virus before it left. It is not a bounce: nothing bounced, because nothing was sent.

Report it wherever your provider has one. SMTP2GO's `reject`, SendGrid's `dropped`, SES's `Reject`, Resend's `email.failed` and `email.suppressed`, and Mailgun's `failed` with a `suppress-` reason are all this. It is a different fact from a bounce and reporting it as a bounce loses that.

**Set `hard` on every drop, because a refused message is not a refused address.** This is the second meaning of `Event::$hard` and it decides whether somebody comes off a mailing list:

- `hard = true` — the provider refused **the address**. It is on that provider's suppression list, bounced there before, complained there before, unsubscribed there, or is not a deliverable address at all. A store may treat that as permanent.
- `hard = false` — the provider refused **this message**. A daily quota, a virus scan, content it did not like, a header it would not write. The address is fine, and a store that suppressed on it would take a customer off its list for something the customer did not do.

`null` reads as `false`. `Event::isRefusedAddress()` is the question a suppression list should ask, and it is true only for a drop whose `hard` is true.

Where the provider gives you a reason, match on the words rather than on the whole sentence, case-insensitively, and let anything you cannot place be `false` — a store losing a subscriber it should have kept is a worse mistake than one queueing a message that will be refused again. What each provider answers today:

| Provider | The address (`true`) | The message (`false`) |
|---|---|---|
| SMTP2GO `reject` | a reason naming the suppression list, a previous bounce, a complaint or an unsubscribe | an unverified sender, and anything else |
| SES `Reject` | never | always — Amazon's `Reject` is content, "Bad content" or a virus |
| SendGrid `dropped` | `Unsubscribed Address`, `Bounced Address`, `Spam Reporting Address`, `Invalid` | `Invalid SMTPAPI header`, `Spam Content`, `Recipient List over Package Quota` |
| Mailgun `failed` with a `suppress-` reason | always — that is what the prefix means | never |
| Resend | `email.suppressed` | `email.failed` |
| Postmark, MailerSend | neither: they report no drop at all | |

`verify()` answers a `Verdict`: `verified()` when the signature checked out, `unsigned()` when your provider signs nothing and the URL secret was the whole check, `refused('why')` when it did not, and `confirm($url)` when this request was the provider asking you to fetch a URL to prove the address is yours — Amazon's SNS subscriptions begin that way. Name the URL and check it is really the provider's own host; the caller makes the request, because a provider is a pure function of a request and fetching a URL is not.

The refusal reason is written for the store's log, in plain words. It is never sent back to the caller: telling a forger which check they failed is telling them what to fix.

### `setup()`

Null when there is no API for it. Otherwise a driver sets itself up from the pasted key, and manual steps are the fallback rather than the plan — a merchant who has already given your plugin an API key should not then have to find a dashboard, guess which of four things called "webhooks" is the right one, tick five boxes, register a custom header by hand and pick an output format, every one of which is a place to get it silently wrong.

```php
interface WebhookSetup
{
    public function create(string $url, array $events, array $config): SetupResult;
    public function permissionsNeeded(): string;
}
```

Pressing the button twice must not leave a store with two webhooks posting the same events at the same address, so look for your own and update it where the API allows that. `SetupResult::failed()` takes a finished sentence a merchant can act on — "403" is not a message, and "The API key was refused. It needs the Manage Webhooks permission, which is set on the key's own page" is. `permissionsNeeded()` is separate because that sentence is the same every time and is worth showing before the button is pressed.

### `domain()`

```php
new DomainFacts(
    spfInclude: 'spf.smtp2go.com',
    dkimZone: 'dkim.smtp2go.net',
    returnPathZone: 'return.smtp2go.net',
    lookup: fn (string $domain): array => $this->api->domainFacts($domain),
);
```

The selector itself is deliberately not here. Selectors are per domain and per account — one provider uses `s` plus the account's numeric id, another generates three random tokens, a third uses a date — so there is nothing to guess and no way to walk a zone to find one. What is here is the convention: the zone a selector points into, which is enough to tell a real selector from a name that happens to resolve, and enough to spot the store that changed provider and left last year's record behind.

`lookup` is the way out of that when your API will say. It is handed a domain and answers `['selectors' => [...], 'return_paths' => [...]]`, either key absent meaning "could not say". It must never throw and it must have a short timeout: a provider's API being slow is an unanswered question, not a broken settings screen. Callers cache the answer.

### A transport with no delivery API

Do nothing. Do not register a provider that answers null from everything; that is worse than registering none, because a store then draws a card for a provider that can say nothing about anything. Leave `onEmailProviders` unimplemented and the store will say the transport cannot report deliveries, which is true and is the more useful sentence.

## Receiving mail

Inbound mail is a second, optional capability, asked for with `Email::supportsFeature('inbound')` (true on PHP 8.1 and later, like the rest of the contract). Everything lives under `Grav\Plugin\Email\Providers\Inbound\`, plus the IMAP client under `Grav\Plugin\Email\Inbound\Imap\`. None of it is used until something calls it, so a site that never receives mail behaves exactly as before.

It is not a new method on `Provider`. Every transport plugin implements `Provider` today, and a new method there would be a fatal error in every one that wasn't updated in the same release. Inbound is a separate interface your provider class may also implement.

### What a provider plugin writes

Implement `InboundCapable` on the provider class you already register on `onEmailProviders`, and return a receiver:

```php
use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;

final class PostmarkProvider implements Provider, InboundCapable
{
    // … the Provider methods you already have …

    public function inbound(): InboundReceiver
    {
        return new PostmarkInbound();
    }
}
```

There is no second event and no second registry: the gateway finds the receiver on the provider it already knows. `inbound()` is called whenever a consumer lists receivers, so keep it cheap, with no I/O.

```php
interface InboundReceiver
{
    public function key(): string;                 // 'postmark' — lowercase, a route segment
    public function label(): string;               // 'Postmark' — a brand name, never translated
    public function verificationKeys(): array;     // config keys verify() reads, e.g. ['inbound_username', 'inbound_password']
    public function maxBytes(): int;               // the largest body you accept, in bytes
    public function verify(InboundRequest $request, array $config): Verdict;
    public function parse(InboundRequest $request, array $config): InboundPayload;
    public function fetch(InboundReference $ref, array $config): InboundMessage;
    public function instructions(string $webhookUrl): string;
}
```

The rules are the delivery-report rules with two additions:

- **Authenticate before acting.** `verify()` runs before `parse()`, and nothing is stored or acted on for a refused request. Where the scheme signs raw bytes, check `$request->body` before decoding anything. Where the signature sits inside the payload, decode only what the check needs.
- **`parse()` never throws and does no network I/O.** It runs on a public address anybody can post to. Anything it can't read is `InboundPayload::unreadable('what was wrong')`; the consumer logs it and answers 200.
- **`fetch()` runs in the consumer's worker.** A provider that sends only metadata (Resend's `email.received`, a Mailgun `store()` notification) answers an `InboundReference` from `parse()`. The consumer stores that, answers 200 at once, and calls `fetch()` later from its own job worker to download the message. `fetch()` may throw; the worker retries.
- **Size.** The gateway refuses a body over `maxBytes()`, or over the consumer's own `max_bytes` when that is smaller, with 413 before `verify()` runs. Answer the provider's real limit (SendGrid's 30 MB, SNS's 150 KB inline content), not a guess.
- **Unsigned providers.** A provider that signs nothing answers `Verdict::unsigned()` after whatever check it does offer (basic auth credentials in the URL, say). A consumer accepts that only behind a URL secret of at least 32 random characters, and labels the receiver "authenticated by secret URL" wherever a person sees it.
- **`verificationKeys()`** name keys in your own plugin's config, beside the sending credentials, exactly as for delivery reports. The consumer passes those values in `$config`.

`InboundRequest` is `WebhookRequest` plus what multipart posts need. `$body` is the raw bytes, or `''` for `multipart/form-data`, where PHP never fills `php://input`; the form fields are then in `$parsedBody` (read one with `field('name')`) and the files in `$files`, a list of `InboundUpload{field, filename, type, size, tmpPath}`. Headers are lower-cased; `header()`, `hasHeader()`, `contentType()` and `json()` work as on `WebhookRequest`.

Build messages with `InboundMessage::fromMime($raw, $this->key(), $overrides)` whenever you have the raw MIME, and lay what the provider knows over it: `['envelopeTo' => [...], 'envelopeFrom' => '', 'providerId' => '…', 'auth' => ['spf' => 'pass', …], 'spamScore' => 1.2, 'providerStrippedText' => '…']`. When the provider gives only fields, construct `InboundMessage` with named arguments and `InboundAttachment`s built from its attachment list (`content` for base64 fields, `path` for uploaded files).

### What a message looks like

`InboundMessage` holds UTF-8 strings throughout:

| Property | What it is |
|---|---|
| `raw` | the full RFC 5322 bytes, when the receiver had them |
| `messageId`, `inReplyTo`, `references` | ids without angle brackets, lower-cased; `inReplyTo` is the first id only |
| `from`, `to`, `cc`, `replyTo` | `Address{email, name}` values; `Address::detail()` answers the `+detail` of a plus address |
| `envelopeTo`, `envelopeFrom` | the SMTP envelope where known; `envelopeFrom === ''` is the null sender (a bounce) |
| `subject`, `date` | decoded subject; Unix time or null |
| `text`, `html` | the body parts, either may be null; `format=flowed` text is already joined |
| `providerStrippedText` | the provider's own quote-stripped text, advisory only |
| `headers` | every header in order as `[name, value]`, unfolded but not decoded; `header($name)` answers the first value, `headerAll($name)` all of them |
| `attachments` | `InboundAttachment{filename, contentType, size, contentId, inline, content, path}`; `bytes()` reads either |
| `auth` | `['spf' => …, 'dkim' => …, 'dmarc' => …]` from the topmost `Authentication-Results`, or from the receiver; a missing key means unknown |
| `spamScore`, `receiver`, `providerId`, `contentType` | `contentType` is the top-level MIME type; `isReport()` is true for `multipart/report` |

From raw MIME alone, the topmost `Return-Path` gives `envelopeFrom` and the topmost `X-Original-To` or `Delivered-To` gives `envelopeTo`, which is what an IMAP mailbox has to go on. A receiver that knows the real envelope overrides both.

`MimeParser` is written in this plugin rather than bundled. Every maintained MIME library for PHP brings packages Grav core also ships at its own version (`guzzlehttp/psr7`, `pimple/pimple`, `psr/container`) or a dependency-injection container, and Grav loads every plugin's autoloader into one process. It covers what received mail needs: RFC 2047 encoded words, folded headers, nested multiparts, quoted-printable and base64, charsets through mbstring and iconv (ISO-8859-1 read as Windows-1252, as mail clients do), RFC 2231 filenames, inline `cid:` parts, `message/rfc822` kept whole as an attachment, and `multipart/report`. `parse()` never throws.

### Built-in receivers

Two receivers ship with this plugin and need no transport plugin: `cloudflare`, for a Cloudflare Email Routing Worker, and `generic`, for anything else that can sign a raw message. Both take the raw message as the body with an HMAC-SHA256 signature over it (`X-Grav-Signature: t={unix},v1={hex}`, 300-second tolerance), and read the envelope from `X-Grav-Envelope-To` and `X-Grav-Envelope-From`. Their config is the consumer's: `secret` (required, at least 32 characters), `tolerance` (seconds, default 300) and `max_bytes`. `docs/inbound-cloudflare.md` has the Worker source, the setup steps, and a shell and a PHP sender. The keys `cloudflare` and `generic` are reserved; a provider receiver that claims one is left out.

### IMAP

For a mailbox with no webhook at all (Gmail with an app password, most hosting mailboxes), `Grav\Plugin\Email\Inbound\Imap\ImapMailbox` is a small pure-PHP IMAP client over `stream_socket_client`. It does not use ext/imap, which left PHP core in 8.4.

```php
use Grav\Plugin\Email\Inbound\Imap\ImapConfig;
use Grav\Plugin\Email\Inbound\Imap\ImapException;
use Grav\Plugin\Email\Inbound\Imap\ImapMailbox;

try {
    $mailbox = ImapMailbox::connect(ImapConfig::fromArray([
        'host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl',
        'username' => 'support@example.com', 'password' => $appPassword, 'mailbox' => 'INBOX',
    ]));
} catch (ImapException $e) {
    // $e->kind is 'auth' (fix the settings), 'network' (try later) or 'protocol'
}

if ($mailbox->uidValidity() !== $storedUidValidity) {
    $lastUid = 0;   // the server renumbered; rely on your own dedupe
}
foreach ($mailbox->fetchNew($lastUid, 50, $maxBytes) as $item) {   // ImapItem{uid, raw, size}
    if (!$item->isTooLarge()) {
        $message = InboundMessage::fromMime($item->raw, 'imap');
        // store it
    }
    $mailbox->markProcessed($item->uid, 'Processed');   // \Seen, then moved when a folder is given
    $lastUid = $item->uid;
}
$mailbox->close();
```

`encryption` is `ssl` (TLS from the first byte, port 993), `starttls` (port 143, upgraded before login; it never falls back to plain text) or `none` (a local test server only). Certificates are verified unless `verify_peer` is false. `fetchNew()` downloads with `BODY.PEEK[]`, so nothing is marked read until `markProcessed()`. With a last UID of 0 it asks for unseen mail only; after that, every UID above the last one. A message over `$maxBytes` is not downloaded and comes back with a null `raw`. `markProcessed()` uses `UID MOVE` where the server has it, and `UID COPY` + `\Deleted` + `UID EXPUNGE` (or `EXPUNGE` without UIDPLUS) where it doesn't, creating the folder when the server says `TRYCREATE`. Only `LOGIN` is supported today; `ImapConfig::AUTH_XOAUTH2` is reserved for `AUTHENTICATE XOAUTH2`.

### Calling it from a consumer

A consumer (a helpdesk, a forum, a store) calls one thing, `InboundGateway`:

```php
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;

$email = Grav::instance()['Email'] ?? null;
if ($email === null || !method_exists($email, 'supportsFeature') || !$email::supportsFeature('inbound')) {
    return; // this copy of the Email plugin can't receive mail
}

$gateway = new InboundGateway($email);
$result = $gateway->receive($receiverKey, InboundRequest::fromGlobals(), $config);

http_response_code($result->status);   // 404 unknown receiver, 413 too large, 401 refused, 200 accepted
if ($result->accepted()) {
    foreach ($result->messages() as $message) { /* store, then process later */ }
    foreach ($result->references() as $ref) { /* store, fetch() later in the worker */ }
    if ($result->payload->confirmUrl !== null) { /* fetch it to confirm an SNS subscription */ }
    if ($result->payload->unreadable) { /* log $result->payload->note */ }
}
```

`receive()` resolves the receiver, checks the size, verifies, and parses, in that order, stopping at the first that fails. `$config` is the receiver's verification config (for a provider receiver, usually that provider plugin's own config) plus `max_bytes` if you have a limit of your own. `receivers()` lists every receiver on the site for a settings screen, and `receiver($key)` finds one. The refusal reason in `$result->verdict->reason` is for your log, never for the response body.

Match the URL secret in your own route before calling the gateway, compare it with `hash_equals`, and answer 404 with no body on a mismatch. Answer 200 once the message is stored and do the processing afterwards: providers retry on anything else, some for days. Answer 5xx only when you couldn't store the message, so the provider keeps it.

### Testing a receiver

The same way as delivery reports: the provider's documented sample payloads saved as fixtures, parsed and checked field by field, and every signature computed in the test and then broken. `InboundRequest` is built directly in a test with named arguments (`new InboundRequest(headers: [...], body: $raw)`), so none of this needs Grav or a running site.

## The send id header

One name, owned by this plugin, answered by `SendHeader::name()`, and used by both ends.

It is `X-Grav-Send-Id`. It used to be `X-KahunaCart-Send`, because KahunaCart's newsletter add-on was the only thing that had ever stamped one, and a Team Grav transport plugin carrying another product's name in a string a merchant reads is not something to keep.

Two ways to change it, and a store needs neither in the ordinary case:

- `providers.send_header` in the Email plugin's configuration, which is what a merchant has. It is worth setting only when the site's mail already carries a header of that name, or when the provider account is shared with something else that reads one.
- `SendHeader::override('X-Something-Else')`, which is what code has. It wins over the config and lasts for the request, so an add-on that decides the name itself never has to write to anybody's config file.

Whatever it ends up as, it is the same string in three places that would otherwise drift: the header an add-on puts on the message, the name a provider's setup registers with the provider, and the name every `parse()` looks for on the way back. A provider that only echoes a header you have registered with it — SMTP2GO is one — has to have its webhook set up again after a change, because a header that is not registered is a header that is never echoed. Say so where a merchant will read it.

## Where the credentials live

Two different secrets, owned by two different plugins, and they are constantly confused.

**The URL secret** belongs to whatever built the webhook address — an add-on that receives delivery reports, typically. It is the random string in the path of the URL a merchant pastes into a provider's dashboard, and for a provider that signs nothing it is the whole of the protection. It is that add-on's to mint, store and print on its own settings screen.

**The verification keys** belong to your plugin. Mailgun's HTTP webhook signing key, SendGrid's verification key, Postmark's basic auth pair — every one of them is a credential for talking to that provider, and it goes in your plugin's own blueprint beside the sending credentials you already keep. `verificationKeys()` names them and `verify()` is handed their values.

The rule is: if losing it would let somebody forge events from the provider, it is yours. If losing it would let somebody find the store's address, it is the store's.

## Reading the contract from another plugin

Ask, do not compare version numbers — a version comparison hard-codes a release into every caller and gets it wrong the first time a fix is backported.

```php
$email = Grav::instance()['Email'] ?? null;

if ($email !== null && method_exists($email, 'supportsFeature') && $email::supportsFeature('providers')) {
    $provider = $email::providerFor($engine);   // null when no plugin registered one
} else {
    // whatever you were doing before
}
```

`Email::providers()` answers the whole registry, `providerFor(string $engine)` finds the one whose `engines()` names an engine, and `providerByKey(string $key)` finds one by key. All three answer null or an empty registry rather than throwing, and null is a real answer with a plain meaning: this transport cannot report deliveries. Say that, rather than showing a merchant an address nothing will ever post to.

## Testing a provider

Against the provider's own documented sample payloads, saved as fixtures, parsed, and checked field by field. That is the test that catches the thing nothing else catches — every one of these providers renames a field eventually, and the failure is silent. Check the timestamp too: a date format nobody parsed reads as zero and gets quietly stamped with the receiver's clock, and a whole store's charts are then wrong in a way nobody can see.

For signatures, compute them in the test rather than pasting one, then break them: change a byte of the body, replace the signature, replay an old timestamp, point a certificate URL at a host that merely ends with the provider's domain. Every one of those is a real attack and every one is three lines of test.

`WebhookRequest` is built directly in a test — a method, a path, a headers array, a body string — so none of this needs Grav, a route, or a running site.
