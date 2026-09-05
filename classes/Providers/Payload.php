<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * Everything one webhook request turned out to be.
 *
 * Nearly always a list of {@see Event}s and nothing else. The other two answers
 * matter because they are the two ways "no events" happens, and they are not
 * the same thing:
 *
 * - **Nothing, with a note.** A perfectly good payload carrying an event this
 *   store does not act on — `processed`, `deferred`, `email.delivery_delayed`.
 *   A provider skips those rather than refusing them, and the note is what a
 *   merchant reading their log needs so a 200 with no effect does not look like
 *   a failure.
 * - **Unreadable, with a note.** The body was not the structure this provider
 *   sends at all. The caller logs the first few hundred bytes of it, because
 *   that is the difference between a merchant who can see what arrived and one
 *   reading "nothing happened".
 *
 * `confirmUrl` is for Amazon, whose delivery webhook is not really a webhook at
 * all but an SNS topic subscription, and whose first ever request asks the
 * endpoint to fetch a URL to prove it is theirs. A provider names the URL and
 * the caller fetches it, because a provider is a pure function of a request and
 * making an outbound request is not. The same answer is available on
 * {@see Verdict::confirm()} for a provider that recognises the confirmation
 * before it gets as far as parsing.
 *
 * ## `parse()` never throws
 *
 * That is the rule, and this class is what makes it easy to keep. Whatever
 * arrives — truncated JSON, an XML error page from a proxy, an empty body, a
 * field that is a list where the documentation says an object —
 * {@see unreadable()} is the answer. An exception out of `parse()` would be an
 * exception on a public route that anybody can post to.
 */
final class Payload
{
    /** @param list<Event> $events */
    public function __construct(
        public readonly array $events = [],
        /** A URL to fetch to confirm a subscription, checked by the provider before it is named. */
        public readonly ?string $confirmUrl = null,
        /** What to write in the log when there was nothing to record. */
        public readonly string $note = '',
        /** Whether the body was not this provider's structure at all, as opposed to an event nobody acts on. */
        public readonly bool $unreadable = false,
    ) {
    }

    /** @param list<Event> $events */
    public static function of(array $events): self
    {
        return new self(array_values($events));
    }

    /** Nothing to do, and why. */
    public static function nothing(string $note = ''): self
    {
        return new self([], null, $note);
    }

    /** The body was not what this provider sends, and here is what to log. */
    public static function unreadable(string $note): self
    {
        return new self([], null, $note, true);
    }

    /** The provider asking us to confirm we meant to subscribe. */
    public static function confirm(string $url): self
    {
        return new self([], $url, 'a subscription confirmation');
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
