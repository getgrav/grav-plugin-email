<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * What a provider decided about a webhook request before anything read its
 * body as anything but bytes.
 *
 * Four answers rather than a boolean, because "this is genuinely from them",
 * "this is forged", "this provider signs nothing so the URL secret was the
 * whole check" and "this is the provider asking us to confirm we meant to
 * subscribe" are four different things and the route answers each of them
 * differently.
 *
 * A refusal is a 401 and a log line. An unsigned pass is a 200 and the events
 * are acted on. A confirmation names a URL the caller fetches to prove the
 * address is theirs, which is how Amazon's SNS subscriptions begin and is a
 * side effect a provider has no business performing itself.
 *
 * The reason on a refusal is written for the store's log, in plain words, and
 * is never sent back to the caller: telling a forger which check they failed
 * is telling them what to fix.
 */
final class Verdict
{
    public function __construct(
        public readonly bool $ok,
        /** Whether this provider signs at all. False means the URL secret was it. */
        public readonly bool $signed = false,
        /** Why not, in plain words, for the store's log. Never shown to the caller. */
        public readonly string $reason = '',
        /** An https URL to fetch to confirm a subscription, where that is what this request was. */
        public readonly ?string $confirmUrl = null,
    ) {
    }

    /** A signature that checked out. */
    public static function verified(): self
    {
        return new self(true, true);
    }

    /**
     * A provider with no signature to check.
     *
     * The URL secret already matched before this was reached, and for a
     * provider that signs nothing that secret is the whole of the protection —
     * which is why it is long and why the route compares it in constant time.
     */
    public static function unsigned(): self
    {
        return new self(true, false);
    }

    /** Refused, and why. */
    public static function refused(string $why): self
    {
        return new self(false, true, $why);
    }

    /**
     * The provider is asking us to fetch a URL to prove this address is ours.
     *
     * Amazon's SNS `SubscriptionConfirmation` is the one this exists for. The
     * provider names the URL and checks it is really the provider's own host;
     * the caller makes the request, because a provider is a pure function of a
     * request and fetching a URL is not.
     */
    public static function confirm(string $url): self
    {
        return new self(true, true, '', $url);
    }
}
