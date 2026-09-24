<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

use Grav\Plugin\Email\Providers\Verdict;

/**
 * The half of a provider that reads mail sent *to* a site.
 *
 * It follows the same split as {@see \Grav\Plugin\Email\Providers\DeliveryReports}:
 * a cheap {@see verify()} that decides whether the request is genuine before
 * anything acts on it, then a {@see parse()} that never throws. The consumer
 * never calls either directly; it calls {@see InboundGateway::receive()}, which
 * runs them in order after a size check.
 *
 * ## Authenticate before acting
 *
 * Over the raw bytes wherever the scheme signs raw bytes. An HMAC over the body
 * is checked on `$request->body` before anything decodes it. A scheme whose
 * signature sits inside the payload — Mailgun's form fields, an SNS JSON
 * envelope — decodes only what the check needs, checks it, and stops there.
 * Nothing is stored, logged as a message or acted on for a refused request.
 *
 * ## `parse()` never throws and does no network I/O
 *
 * It runs on a public address anybody can post to. Whatever arrives — a
 * truncated body, a proxy's error page, a field that is a list where the
 * documentation says a string — the answer is
 * {@see InboundPayload::unreadable()}. A receiver whose provider only sends
 * metadata answers an {@see InboundReference} from `parse()` and does the
 * download in {@see fetch()}, which the consumer runs later in its own job
 * worker, never inside the webhook request.
 *
 * ## Unsigned providers
 *
 * Some providers sign nothing. Their `verify()` answers
 * {@see Verdict::unsigned()} after whatever check the provider does offer
 * (basic auth credentials in the URL, say), and the consumer only accepts that
 * behind a URL secret of at least 32 random characters, labelled "authenticated
 * by secret URL" wherever a person sees it.
 */
interface InboundReceiver
{
    /** Short lowercase key: 'postmark', 'mailgun', 'cloudflare', … */
    public function key(): string;

    /** The name a person calls it. A brand name, never translated. */
    public function label(): string;

    /**
     * The config keys this receiver reads for verification, e.g.
     * `['signing_key']`. For a provider receiver these live in the transport
     * plugin's own config beside its sending credentials; for a built-in
     * receiver they are the consumer's to keep.
     *
     * @return list<string>
     */
    public function verificationKeys(): array;

    /** The largest body this receiver accepts, in bytes. */
    public function maxBytes(): int;

    /**
     * Whether the request is genuinely from the provider.
     *
     * No I/O, except an SNS certificate fetch through the Amazon plugin's
     * certificate store.
     *
     * @param array<string, mixed> $config
     */
    public function verify(InboundRequest $request, array $config): Verdict;

    /**
     * The messages in a verified request. Never throws; unreadable input is
     * {@see InboundPayload::unreadable()}. No network I/O.
     *
     * @param array<string, mixed> $config
     */
    public function parse(InboundRequest $request, array $config): InboundPayload;

    /**
     * The full message behind a reference {@see parse()} answered. Runs in the
     * consumer's job worker, never in the webhook request. May throw: the
     * worker retries.
     *
     * @param array<string, mixed> $config
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage;

    /** Plain setup steps for an admin screen: addresses, DNS, where the URL goes. */
    public function instructions(string $webhookUrl): string;
}
