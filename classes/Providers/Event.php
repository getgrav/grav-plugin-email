<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * One thing a provider told us about a message, in the words every provider
 * has in common.
 *
 * Six providers, six payload formats, one vocabulary. A provider's whole job in
 * {@see DeliveryReports::parse()} is to turn one of those formats into a list of
 * these, and from here on nothing downstream knows or cares whether a bounce
 * arrived as Amazon's `bounceType: Permanent`, Postmark's `Type: HardBounce`,
 * Mailgun's `severity: permanent` or SendGrid's `type: bounce`. That is the
 * point: a store's handling is written once, and a provider added later is a
 * class and a set of fixtures rather than another branch in it.
 *
 * ## The fields, and why nearly all of them are nullable
 *
 * - **`type`** is one of {@see TYPES} and is the only one that is never absent.
 *   An event whose type a provider cannot name is not an event: it is skipped,
 *   not refused.
 * - **`hard`** is true for a permanent failure, false for a temporary one, and
 *   null for anything that is not a bounce or a drop — and also for a bounce whose
 *   provider would not say. Amazon's `Undetermined` lands on null, and a null
 *   should be read as soft, because suppressing an address on a maybe is how a
 *   store loses a customer who was behind a full mailbox for an afternoon. On a
 *   {@see DROPPED} it means something else, and the two meanings are set out
 *   under "A refused message is not a refused address" below.
 * - **`email`** is the recipient. Several providers report a bounce with the
 *   address and nothing else, and that is still enough to suppress on.
 * - **`messageId`** is *our* `Message-ID`, angle brackets taken off, where the
 *   provider echoes it. Not the provider's own id for the message — that is
 *   `providerId`, and the two are constantly confused because Postmark and
 *   SendGrid both call theirs `MessageID`.
 * - **`providerId`** is the provider's own handle, worth keeping so a merchant
 *   can paste it into the provider's dashboard.
 * - **`at`** is when the provider says it happened, in whole seconds. A payload
 *   with no timestamp answers 0 and the caller stamps it with the moment it
 *   arrived, which is a few seconds out and better than a null a chart skips.
 * - **`reason`** is the provider's own words, kept short. Written down rather
 *   than translated, because "550 5.1.1 user unknown" is the answer to the
 *   merchant's question and a translation of it would not be.
 * - **`sendId`** is whatever came back in the header named by
 *   {@see DeliveryReports::sendHeader()}, where the provider echoes custom
 *   headers or metadata. This is the correlation path for every provider that
 *   mints its own message id and never repeats ours.
 *
 * ## A refused message is not a refused address
 *
 * `hard` has a second meaning on {@see DROPPED}, and it is the difference
 * between a subscriber who is gone and a subscriber who happened to be on the
 * list the morning the store ran out of quota.
 *
 * - **`hard === true`** — the provider refused **the address**. It is on that
 *   provider's own suppression list: it bounced there, complained there,
 *   unsubscribed there, or is not a deliverable address at all. A store may
 *   treat that as permanent, because nothing sent to that address through this
 *   transport is ever going to arrive.
 * - **`hard === false` or `null`** — the provider refused **this message**. A
 *   daily quota, a virus scan, content it did not like, a header it would not
 *   write. The address is fine and the next message to it may well go out. A
 *   store that suppressed on this would take a customer off its list for
 *   something the customer did not do.
 *
 * A provider that reports a drop says which one it is: SendGrid's
 * `Unsubscribed Address` is the address and its `Spam Content` is the message,
 * Mailgun's `suppress-*` is always the address, and Amazon's `Reject` is always
 * the message. {@see isRefusedAddress()} is the question a suppression list
 * should be asking.
 */
final class Event
{
    public const DELIVERED = 'delivered';
    public const BOUNCED = 'bounced';
    public const COMPLAINED = 'complained';
    public const OPENED = 'opened';
    public const CLICKED = 'clicked';

    /**
     * The provider refused to send at all — the address is on its own
     * suppression list, or it decided the message was spam before it left.
     * Distinct from a bounce because nothing was ever handed to a receiving
     * server, and a store may reasonably treat it differently.
     *
     * `hard` says which of the two refusals it was; see the class note.
     */
    public const DROPPED = 'dropped';

    /** @var list<string> */
    public const TYPES = [
        self::DELIVERED,
        self::BOUNCED,
        self::COMPLAINED,
        self::OPENED,
        self::CLICKED,
        self::DROPPED,
    ];

    /** How much of a provider's explanation is worth carrying. */
    public const MAX_REASON = 500;

    public function __construct(
        public readonly string $type,
        public readonly ?bool $hard = null,
        public readonly ?string $email = null,
        public readonly ?string $messageId = null,
        public readonly ?string $providerId = null,
        public readonly int $at = 0,
        public readonly ?string $reason = null,
        public readonly ?string $sendId = null,
    ) {
    }

    /**
     * Build one with every field put through the same tidying.
     *
     * A named constructor rather than work in the constructor, so the value
     * object stays a value object: a provider calls this, a test builds one
     * directly with exactly the fields it means, and neither has to remember
     * that a message id arrives in angle brackets about half the time.
     */
    public static function of(
        string $type,
        ?bool $hard = null,
        ?string $email = null,
        ?string $messageId = null,
        ?string $providerId = null,
        ?int $at = null,
        ?string $reason = null,
        ?string $sendId = null,
    ): self {
        $reason = $reason === null ? null : trim((string)preg_replace('/\s+/', ' ', $reason));

        return new self(
            $type,
            $hard,
            self::address($email),
            self::messageId($messageId),
            self::trimmedOrNull($providerId),
            max(0, (int)$at),
            $reason === null || $reason === '' ? null : mb_substr($reason, 0, self::MAX_REASON),
            self::trimmedOrNull($sendId),
        );
    }

    /** Whether this is one of the six words everything downstream knows. */
    public function known(): bool
    {
        return \in_array($this->type, self::TYPES, true);
    }

    /** A bounce the provider called permanent. A null `hard` is not permanent; see above. */
    public function isHardBounce(): bool
    {
        return $this->type === self::BOUNCED && $this->hard === true;
    }

    /**
     * A drop where the provider refused the address rather than the message.
     *
     * True only for a {@see DROPPED} whose `hard` is true. Everything else is
     * false, including a drop the provider would not classify — a store should
     * not take somebody off its list on a maybe, and a provider that knows the
     * address is finished says so.
     */
    public function isRefusedAddress(): bool
    {
        return $this->type === self::DROPPED && $this->hard === true;
    }

    /** The same event with a moment on it, for a payload that carried none. */
    public function at(int $at): self
    {
        return $this->at > 0 ? $this : new self(
            $this->type,
            $this->hard,
            $this->email,
            $this->messageId,
            $this->providerId,
            max(0, $at),
            $this->reason,
            $this->sendId,
        );
    }

    /**
     * The event as plain data, for a log line or a test.
     *
     * @return array{type: string, hard: bool|null, email: string|null, message_id: string|null, provider_id: string|null, at: int, reason: string|null, send_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'hard' => $this->hard,
            'email' => $this->email,
            'message_id' => $this->messageId,
            'provider_id' => $this->providerId,
            'at' => $this->at,
            'reason' => $this->reason,
            'send_id' => $this->sendId,
        ];
    }

    /**
     * An address, lower-cased and with any display name taken off.
     *
     * Providers are not consistent about this: some report the bare address and
     * some repeat whatever was in the `To` header, which may well be
     * `Jane Smith <jane@example.com>`. A store keys its suppression list on the
     * address, so one spelling has to win.
     */
    private static function address(?string $email): ?string
    {
        $email = trim((string)$email);
        if ($email === '') {
            return null;
        }

        if (preg_match('/<([^<>]+)>\s*$/', $email, $m) === 1) {
            $email = trim($m[1]);
        }

        return $email === '' ? null : mb_strtolower($email);
    }

    /** A `Message-ID` without its angle brackets, which are the grammar rather than the id. */
    private static function messageId(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = trim(substr($value, 1, -1));
        }

        return $value === '' ? null : $value;
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
