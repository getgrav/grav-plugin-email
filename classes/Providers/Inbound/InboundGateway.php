<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Providers\Inbound\Receivers\CloudflareReceiver;
use Grav\Plugin\Email\Providers\Inbound\Receivers\GenericReceiver;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\Verdict;

/**
 * The one thing a consumer of inbound mail calls.
 *
 *     $gateway = new InboundGateway($grav['Email']);
 *     $result  = $gateway->receive($receiverKey, InboundRequest::fromGlobals(), $config);
 *     http_response_code($result->status);
 *
 * It knows every receiver on the site: the built-in `cloudflare` and `generic`,
 * plus one from every registered provider that implements
 * {@see InboundCapable}. {@see receive()} runs the same four steps for all of
 * them, in this order, and stops at the first that fails:
 *
 * 1. resolve the receiver by key (404 when there is none);
 * 2. check the size against the smaller of the receiver's {@see InboundReceiver::maxBytes()}
 *    and the consumer's `$config['max_bytes']` (413, before any verification work);
 * 3. {@see InboundReceiver::verify()} (401 when refused);
 * 4. {@see InboundReceiver::parse()}, which never throws (200).
 *
 * A verdict that names a subscription URL to confirm skips parsing and answers
 * {@see InboundPayload::confirm()}; the consumer fetches it.
 *
 * `$config` is the receiver's verification config (its
 * {@see InboundReceiver::verificationKeys()}), plus `max_bytes` if the consumer
 * has a limit of its own. For a provider receiver the consumer usually passes
 * that provider plugin's own config; for the built-ins it passes its own secret.
 */
final class InboundGateway
{
    /** Built-in receiver keys. A provider receiver cannot take one of these. */
    public const BUILT_IN = ['cloudflare', 'generic'];

    /** @var array<string, InboundReceiver>|null */
    private ?array $receivers = null;

    /**
     * @param Email|null            $email     the Email plugin's instance; its provider registry is used
     * @param ProviderRegistry|null $providers a registry to use instead, for tests or callers without Grav
     */
    public function __construct(
        private readonly ?Email $email = null,
        private readonly ?ProviderRegistry $providers = null,
    ) {
    }

    /**
     * Every receiver on the site, keyed by receiver key: the built-ins first,
     * then one per provider that implements {@see InboundCapable}. A provider
     * whose `inbound()` throws is left out rather than taking the endpoint down.
     *
     * @return array<string, InboundReceiver>
     */
    public function receivers(): array
    {
        if ($this->receivers !== null) {
            return $this->receivers;
        }

        $receivers = [
            'cloudflare' => new CloudflareReceiver(),
            'generic' => new GenericReceiver(),
        ];

        foreach ($this->registry()?->all() ?? [] as $provider) {
            if (!$provider instanceof InboundCapable) {
                continue;
            }
            try {
                $receiver = $provider->inbound();
            } catch (\Throwable) {
                continue;
            }
            $key = strtolower(trim($receiver->key()));
            if ($key === '' || isset($receivers[$key])) {
                continue;
            }
            $receivers[$key] = $receiver;
        }

        return $this->receivers = $receivers;
    }

    public function receiver(string $key): ?InboundReceiver
    {
        return $this->receivers()[strtolower(trim($key))] ?? null;
    }

    /**
     * Resolve, size check, verify, parse.
     *
     * @param array<string, mixed> $config
     */
    public function receive(string $receiverKey, InboundRequest $request, array $config): InboundResult
    {
        $receiver = $this->receiver($receiverKey);
        if ($receiver === null) {
            return new InboundResult(
                Verdict::refused(sprintf('There is no inbound receiver called "%s".', $receiverKey)),
                InboundPayload::nothing(),
                null,
                404
            );
        }

        $limit = $receiver->maxBytes();
        $consumerLimit = (int)($config['max_bytes'] ?? 0);
        if ($consumerLimit > 0 && ($limit <= 0 || $consumerLimit < $limit)) {
            $limit = $consumerLimit;
        }
        if ($limit > 0) {
            $declared = $request->header('content-length');
            $size = max($request->size(), ctype_digit($declared) ? (int)$declared : 0);
            if ($size > $limit) {
                return new InboundResult(
                    Verdict::refused(sprintf('The request is %d bytes, over the %d byte limit.', $size, $limit)),
                    InboundPayload::nothing(),
                    $receiver,
                    413
                );
            }
        }

        try {
            $verdict = $receiver->verify($request, $config);
        } catch (\Throwable $e) {
            $verdict = Verdict::refused('Verification failed with an error: ' . $e->getMessage());
        }

        if (!$verdict->ok) {
            return new InboundResult($verdict, InboundPayload::nothing(), $receiver, 401);
        }

        if ($verdict->confirmUrl !== null) {
            return new InboundResult($verdict, InboundPayload::confirm($verdict->confirmUrl), $receiver, 200);
        }

        try {
            $payload = $receiver->parse($request, $config);
        } catch (\Throwable $e) {
            // parse() must never throw; a receiver that does still gets a 200 and a log line.
            $payload = InboundPayload::unreadable('The receiver failed to read the request: ' . $e->getMessage());
        }

        return new InboundResult($verdict, $payload, $receiver, 200);
    }

    private function registry(): ?ProviderRegistry
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        if ($this->email !== null) {
            try {
                return $this->email::providers();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
