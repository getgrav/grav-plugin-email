<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * Everything a mail provider knows about itself, answered by that provider's
 * own Grav transport plugin.
 *
 * How its delivery webhooks are verified and read, how a webhook is created
 * from a pasted API key, what its DNS has to look like, what its transport does
 * to custom headers on the way out. All of it belongs to the plugin that talks
 * to that provider, because adding a provider should mean writing a plugin —
 * not editing whatever add-on happened to need the answer first.
 *
 * ## How to register one
 *
 * The Email plugin fires `onEmailProviders` with a {@see ProviderRegistry}. A
 * transport plugin listens and adds itself:
 *
 *     public static function getSubscribedEvents()
 *     {
 *         return ['onEmailProviders' => ['onEmailProviders', 0]];
 *     }
 *
 *     public function onEmailProviders(Event $event): void
 *     {
 *         $event['providers']->add(new Smtp2goProvider($this->config()));
 *     }
 *
 * That is the whole of it. Anything that wants a provider asks
 * `Email::providerFor($engine)` or `Email::providerByKey($key)`, and nothing
 * anywhere carries a list of provider names.
 *
 * See `docs/providers.md` for the long version, with a worked example.
 *
 * ## What a provider must never do
 *
 * - **Verify before parse.** {@see DeliveryReports::verify()} runs first, over
 *   the raw bytes, before anything has decoded the body.
 * - **Never throw from `parse()`.** Whatever arrives, the answer is a
 *   {@see Payload} — {@see Payload::unreadable()} when the body made no sense.
 *   `parse()` runs on a public address anybody can post to.
 * - **Skip an unrecognised event rather than refusing it.** Every provider
 *   sends more event types than any store acts on.
 * - **Do no I/O in the cheap methods.** {@see engines()}, {@see key()},
 *   {@see label()}, {@see capabilities()}, {@see domain()} and
 *   {@see instructions()} are called every time a settings screen is drawn, and
 *   a network round trip in one of them is a settings screen that hangs when
 *   somebody else's API is slow. The API calls belong in
 *   {@see WebhookSetup::create()}, which is behind a button, and in
 *   {@see DomainFacts::$lookup}, which is cached.
 * - **Answer null rather than inventing an answer.** A transport with no
 *   delivery API answers null from {@see reports()}. A provider with no way to
 *   create a webhook answers null from {@see setup()}. Both are complete
 *   answers and a store says so plainly.
 */
interface Provider
{
    /**
     * The engine keys this provider answers for, e.g. `['smtp2go']` or
     * `['ses']`.
     *
     * These are the keys the plugin registers on `onEmailEngines` and that a
     * merchant chooses in the Email plugin's Mail Engine field. Several is
     * normal for a provider that has offered its transport under more than one
     * name over the years; the registry refuses two providers claiming the same
     * one.
     *
     * @return list<string>
     */
    public function engines(): array;

    /**
     * A short key for routes and config, lowercase, e.g. 'smtp2go'. Distinct
     * from the engine only when one provider has several engines.
     */
    public function key(): string;

    /** The name a person calls this provider, for a card title. A brand name, never translated. */
    public function label(): string;

    /** What this transport does to a message on the way out. */
    public function capabilities(): Capabilities;

    /** Null when the provider cannot report deliveries at all. */
    public function reports(): ?DeliveryReports;

    /** Null when there is no API to create the webhook with. */
    public function setup(): ?WebhookSetup;

    /** What this provider needs a sending domain's DNS to say. */
    public function domain(): DomainFacts;

    /**
     * Plain words for doing it by hand in the provider's dashboard, one
     * paragraph, a language key with an English fallback.
     *
     * Name the screens and the boxes. "Configure a webhook" is not
     * instructions, and every one of these dashboards calls it something
     * different.
     */
    public function instructions(): string;
}
