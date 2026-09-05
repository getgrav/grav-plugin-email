<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * The half of a provider that creates its own webhook from the API key the
 * merchant already pasted in.
 *
 * A driver sets itself up from the pasted key wherever the provider's API
 * allows it, and manual steps are the fallback rather than the plan. A merchant
 * who has already given a plugin an API key with the right permission should
 * not then have to find a dashboard, guess which of four things called
 * "webhooks" is the right one, tick five boxes, register a custom header by
 * hand and pick an output format — every one of which is a place to get it
 * silently wrong.
 *
 * Where a provider has no API for this, {@see Provider::setup()} answers null,
 * and the merchant follows {@see Provider::instructions()} instead. That is a
 * normal, complete answer, not a gap.
 *
 * ## What a good failure looks like
 *
 * Most of the failures here are one thing: the key is real but is not allowed
 * to manage webhooks. That merchant needs to be told which permission, in the
 * provider's own words, because "webhooks" is called something different in
 * every dashboard — which is what {@see permissionsNeeded()} is for and why it
 * is a separate method rather than a sentence buried in a failure message.
 */
interface WebhookSetup
{
    /**
     * Create (or update) the webhook at $url for $events through the API with
     * the plugin's own credentials. Answers ok, a sentence, and the provider's
     * webhook id when it has one.
     *
     * Pressing the button twice must not leave a store with two webhooks
     * posting the same events at the same address, so an implementation looks
     * for its own and updates it where the API allows that.
     *
     * @param list<string>         $events from {@see Event::TYPES}; a provider
     *        maps them to its own names and ignores any it cannot send
     * @param array<string, mixed> $config the transport plugin's own config
     */
    public function create(string $url, array $events, array $config): SetupResult;

    /** What the API key must be allowed to do, in the provider's own words, for the merchant who is refused. */
    public function permissionsNeeded(): string;
}
