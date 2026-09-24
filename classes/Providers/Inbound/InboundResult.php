<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

use Grav\Plugin\Email\Providers\Verdict;

/**
 * What {@see InboundGateway::receive()} made of one request, and the HTTP
 * status the consumer should answer with.
 *
 * - 404: there is no receiver with that key. Answer with no body.
 * - 413: the body is over the receiver's or the consumer's limit. Nothing was
 *   verified or parsed.
 * - 401: {@see Verdict} refused it. Log `$verdict->reason`, never send it back.
 * - 200: verified. `$payload` holds the messages, a note, an unreadable note,
 *   or a URL to confirm a subscription with.
 *
 * A consumer that could not store an accepted message answers 5xx itself so the
 * provider keeps it and retries; that decision is the consumer's.
 */
final class InboundResult
{
    public function __construct(
        public readonly Verdict $verdict,
        public readonly InboundPayload $payload,
        /** Null only when no receiver has the key that was asked for. */
        public readonly ?InboundReceiver $receiver,
        public readonly int $status = 200,
    ) {
    }

    /** Verified and parsed. The payload may still be empty or unreadable. */
    public function accepted(): bool
    {
        return $this->status === 200 && $this->verdict->ok;
    }

    /** @return list<InboundMessage> */
    public function messages(): array
    {
        return array_values(array_filter(
            $this->payload->items,
            static fn ($item): bool => $item instanceof InboundMessage
        ));
    }

    /** @return list<InboundReference> */
    public function references(): array
    {
        return array_values(array_filter(
            $this->payload->items,
            static fn ($item): bool => $item instanceof InboundReference
        ));
    }
}
