<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * The half of a provider that reads its delivery webhooks.
 *
 * A transport plugin whose provider has no delivery API at all answers null
 * from {@see Provider::reports()} and implements none of this. There is no null
 * object to write and none to register: a transport with nothing to report
 * registers nothing, and the store says plainly that this transport cannot
 * report deliveries. That sentence is worth far more to a merchant than a
 * webhook address that will never be posted to.
 *
 * ## Two steps, in this order, always
 *
 * {@see verify()} runs before {@see parse()}, and before the body has been read
 * as anything but bytes. A signature checked after the payload was parsed is a
 * signature protecting nothing: by then a stranger has had a parser walk their
 * JSON. Whatever else an implementation does, it must not decode the body in
 * `verify()` before it has checked the signature over the raw bytes — an HMAC
 * over a body that was decoded and re-encoded does not match however right the
 * key is.
 *
 * ## `parse()` never throws
 *
 * Whatever arrives. Truncated JSON, an XML error page from somebody's proxy, an
 * empty body, a documented field that turned out to be a list. All of them are
 * {@see Payload::unreadable()}, and the caller logs the first few hundred bytes
 * and answers 200 anyway — every one of these providers treats a 4xx as a
 * reason to retry for days, and some treat it as a reason to drop the event
 * outright, so refusing one bad payload turns it into a week of noise or a
 * permanently lost bounce.
 *
 * ## An unrecognised event is skipped, not refused
 *
 * Every one of these providers sends more event types than a store acts on —
 * `processed`, `deferred`, `unsubscribed`, `delivery_delayed` — and a merchant
 * who ticked every box in a provider's dashboard should get a 200 and a quiet
 * log line, not a refusal. An event nobody acts on is not an error. Skip it and
 * carry on with the rest of the batch.
 *
 * ## Test against the provider's own samples
 *
 * The one test worth writing here is the provider's documented sample payloads,
 * saved as fixtures, parsed, and checked field by field — including the
 * timestamp, which a format nobody parsed will otherwise read as zero and be
 * quietly stamped with the receiver's clock. Every field these providers
 * rename, and they do rename them, is a silent bug that only a fixture catches.
 */
interface DeliveryReports
{
    /**
     * The events this provider can send, from {@see Event::TYPES}.
     *
     * What it is *able* to report, not what a given store has ticked. A screen
     * uses it to say "this provider does not report opens" rather than showing
     * an empty chart.
     *
     * @return list<string>
     */
    public function events(): array;

    /**
     * Which config keys under the plugin's own config the verification needs,
     * e.g. `['signing_key']`. Empty means the URL secret is the whole
     * protection.
     *
     * These are the provider's own credentials and they live in the transport
     * plugin's own configuration, beside the sending credentials it already
     * keeps. The secret in the webhook URL is a different thing and belongs to
     * whatever built the URL.
     *
     * @return list<string>
     */
    public function verificationKeys(): array;

    /**
     * Whether the request is genuinely from the provider. Runs before
     * {@see parse()} and before the body is read as anything but bytes.
     *
     * @param array<string, mixed> $config the values of {@see verificationKeys()},
     *        keyed by the same names
     */
    public function verify(WebhookRequest $request, array $config): Verdict;

    /**
     * The events in the body, never throws; unreadable bodies answer
     * {@see Payload::unreadable()}.
     */
    public function parse(WebhookRequest $request): Payload;

    /**
     * The header name the store stamps its send id into, and how to read it
     * back from an event's raw data.
     *
     * The store puts this header on every message it wants to hear about; this
     * provider's {@see parse()} finds it again wherever that provider chose to
     * put echoed headers — a top-level key, a `{name, value}` list, a user
     * variables map, a metadata object — and puts what it finds on
     * {@see Event::$sendId}. The name is answered here so the store and the
     * provider cannot disagree about it.
     */
    public function sendHeader(): string;
}
