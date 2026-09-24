<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * Everything one inbound request turned out to be.
 *
 * The inbound twin of {@see \Grav\Plugin\Email\Providers\Payload}. Nearly
 * always one {@see InboundMessage}, or one {@see InboundReference} for a
 * provider that only sends metadata. The other answers:
 *
 * - **Nothing, with a note.** A well-formed request that carries no mail — a
 *   provider's test ping, an event type that is not a received message.
 * - **Unreadable, with a note.** The body was not what this receiver expects.
 *   The consumer logs the first few hundred bytes and answers 200, because a
 *   provider treats a 4xx as a reason to retry for days.
 * - **Confirm.** An SNS subscription asking to be confirmed. The receiver names
 *   the URL after checking its host; the consumer fetches it.
 */
final class InboundPayload
{
    /** @param list<InboundMessage|InboundReference> $items */
    public function __construct(
        public readonly array $items = [],
        public readonly ?string $confirmUrl = null,
        public readonly string $note = '',
        public readonly bool $unreadable = false,
    ) {
    }

    /** @param list<InboundMessage|InboundReference> $items */
    public static function of(array $items): self
    {
        return new self(array_values($items));
    }

    public static function nothing(string $note = ''): self
    {
        return new self([], null, $note);
    }

    public static function unreadable(string $note): self
    {
        return new self([], null, $note, true);
    }

    public static function confirm(string $url): self
    {
        return new self([], $url, 'a subscription confirmation');
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
