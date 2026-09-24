<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * A message a provider told us about but did not send.
 *
 * Resend's `email.received`, a Mailgun `store()` notification and Brevo's
 * attachment tokens all deliver an id and some metadata, and the message itself
 * has to be downloaded. That download is a network call, so it never happens in
 * the webhook request: the consumer stores this, answers 200, and calls
 * {@see InboundReceiver::fetch()} from its own job worker.
 *
 * `meta` is whatever the receiver needs to fetch it later (a storage URL, a
 * region) plus anything a consumer may want to show before the fetch is done.
 * Keep it JSON-encodable: consumers store it as JSON.
 */
final class InboundReference
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        /** The receiver key that can fetch this. */
        public readonly string $receiver,
        /** The provider's id for the message. */
        public readonly string $id,
        public readonly array $meta = [],
    ) {
    }
}
