<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * What happened when a provider tried to create a webhook through its own API.
 *
 * Three fields, and the middle one is the one that matters. `ok` decides which
 * of two colours a button turns; `message` is what the merchant reads, and it
 * has to be a finished sentence in plain words that names the next thing to do.
 * "403" is not a message. "The API key was refused. It needs the Manage
 * Webhooks permission, which is set on the key's own page in the dashboard" is.
 *
 * `webhookId` is the provider's own handle for the thing that was created,
 * where it hands one back. It is worth keeping so a second press updates the
 * webhook rather than creating a duplicate, and it is null for every provider
 * that does not answer with one.
 */
final class SetupResult
{
    public function __construct(
        public readonly bool $ok,
        /** A finished sentence for the merchant, in plain words, naming what to do next when it failed. */
        public readonly string $message,
        /** The provider's own id for the webhook, where it gave one. */
        public readonly ?string $webhookId = null,
    ) {
    }

    public static function ok(string $message, ?string $webhookId = null): self
    {
        return new self(true, $message, $webhookId);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
