<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound\Receivers;

/**
 * Cloudflare Email Routing, through a small Email Worker that posts each
 * message it is handed to the site, signed.
 *
 * Free, works whichever provider the site sends through, and delivers the raw
 * bytes signed end to end. The Worker source and the setup steps are in
 * `docs/inbound-cloudflare.md`. The 25 MiB limit is Cloudflare's own limit on
 * a message Email Routing accepts.
 */
final class CloudflareReceiver extends SignedRawReceiver
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare Email Routing';
    }

    public function instructions(string $webhookUrl): string
    {
        return 'In the Cloudflare dashboard, open your domain, go to Email Routing and enable it (Cloudflare adds the MX and SPF records). '
            . 'Under Settings, turn on Subaddressing so support+anything@ reaches the support@ rule. '
            . 'Create a Worker from the source in the Email plugin\'s docs/inbound-cloudflare.md, add two secrets to it, '
            . 'WEBHOOK_URL set to ' . $webhookUrl . ' and SIGNING_SECRET set to the signing secret shown here, then deploy it. '
            . 'Back in Email Routing, add a routing rule for your support address with the action "Send to a Worker" and pick that Worker.';
    }
}
