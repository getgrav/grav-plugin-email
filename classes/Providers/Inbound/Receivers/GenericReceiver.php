<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound\Receivers;

/**
 * The same signed raw-MIME scheme as {@see CloudflareReceiver}, for anything a
 * site owner writes themselves: a Postfix or Exim pipe script, a forwarder, a
 * cron job reading a Maildir. `docs/inbound-cloudflare.md` has a shell and a PHP
 * sender that sign and post a `.eml` file.
 */
final class GenericReceiver extends SignedRawReceiver
{
    public function key(): string
    {
        return 'generic';
    }

    public function label(): string
    {
        return 'Signed raw message (any sender)';
    }

    public function instructions(string $webhookUrl): string
    {
        return 'POST each raw message to ' . $webhookUrl . ' with Content-Type: message/rfc822, the envelope recipient in '
            . 'X-Grav-Envelope-To, the envelope sender in X-Grav-Envelope-From, and X-Grav-Signature: t={unix time},v1={hex '
            . 'HMAC-SHA256 of the time, a dot and the body, keyed with the signing secret shown here}. '
            . 'The Email plugin\'s docs/inbound-cloudflare.md has a ready-made shell and PHP sender.';
    }
}
