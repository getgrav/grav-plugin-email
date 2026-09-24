<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * One received email, whichever receiver it came through.
 *
 * Every string is UTF-8. Every message id — `$messageId`, `$inReplyTo`, each of
 * `$references` — is written without its angle brackets and lower-cased, so a
 * consumer compares them with `===` and never has to wonder which spelling a
 * mail client chose.
 *
 * `$headers` keeps every header in its original order as `[name, value]`
 * pairs, unfolded but otherwise as sent: encoded words are not decoded there,
 * so `Authentication-Results` and friends can be read exactly. The decoded
 * values a consumer needs are the properties.
 *
 * The envelope is kept apart from the headers because it is a different fact.
 * `$envelopeTo` is the address the mail was actually delivered to (the SMTP
 * `RCPT TO`), which is where a `support+token@` address survives when the
 * visible To says `support@`. `$envelopeFrom` is the SMTP `MAIL FROM`; `''`
 * means the null sender, which is how bounces and other automatic replies are
 * sent. Both come from the receiver where it knows them. From the MIME alone,
 * the topmost `Return-Path` gives the envelope sender and the topmost
 * `X-Original-To` or `Delivered-To` gives the recipient, which is what an IMAP
 * mailbox has to go on.
 *
 * `$auth` is the receiving server's verdicts where it recorded them —
 * `['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass']` — read from the
 * topmost `Authentication-Results` header or given by the receiver. Absent keys
 * mean unknown, not failed.
 */
final class InboundMessage
{
    /**
     * @param list<Address>                   $to
     * @param list<Address>                   $cc
     * @param list<Address>                   $replyTo
     * @param list<string>                    $envelopeTo
     * @param list<string>                    $references
     * @param list<array{0: string, 1: string}> $headers
     * @param list<InboundAttachment>         $attachments
     * @param array<string, string>           $auth
     */
    public function __construct(
        /** The full RFC 5322 bytes, when the receiver had them. */
        public readonly ?string $raw = null,
        public readonly ?string $messageId = null,
        /** The first id in In-Reply-To only. */
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly Address $from = new Address(''),
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $replyTo = [],
        public readonly array $envelopeTo = [],
        public readonly ?string $envelopeFrom = null,
        public readonly string $subject = '',
        /** Unix time from the Date header, or null when it could not be read. */
        public readonly ?int $date = null,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        /** The provider's own quote-stripped text. Advisory only. */
        public readonly ?string $providerStrippedText = null,
        public readonly array $headers = [],
        public readonly array $attachments = [],
        public readonly array $auth = [],
        public readonly ?float $spamScore = null,
        /** The receiver key it came through: 'cloudflare', 'imap', 'postmark', … */
        public readonly string $receiver = '',
        /** The provider's own id for the message, where it gives one. */
        public readonly ?string $providerId = null,
        /** The top-level media type, lower-cased: 'multipart/report' for a delivery report. */
        public readonly string $contentType = 'text/plain',
    ) {
    }

    /**
     * Parse raw MIME into a message, then lay the receiver's own knowledge over
     * it: `['envelopeTo' => [...], 'envelopeFrom' => '', 'providerId' => '…',
     * 'auth' => [...], 'spamScore' => 1.2, 'providerStrippedText' => '…']`, or
     * any other property by name. Never throws.
     *
     * @param array<string, mixed> $overrides
     */
    public static function fromMime(string $raw, string $receiver, array $overrides = []): self
    {
        $message = (new MimeParser())->parse($raw);

        return $message->with(['receiver' => $receiver] + $overrides);
    }

    /**
     * A copy with some properties replaced. Unknown names are ignored, and a
     * value of the wrong type is ignored rather than thrown on.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $values = get_object_vars($this);
        foreach ($changes as $name => $value) {
            if (!\array_key_exists($name, $values)) {
                continue;
            }
            $values[$name] = $value;
        }

        try {
            return new self(...$values);
        } catch (\TypeError) {
            $safe = get_object_vars($this);
            foreach ($changes as $name => $value) {
                if (!\array_key_exists($name, $safe)) {
                    continue;
                }
                $try = $safe;
                $try[$name] = $value;
                try {
                    new self(...$try);
                    $safe = $try;
                } catch (\TypeError) {
                }
            }

            return new self(...$safe);
        }
    }

    /** The first value of a header, case insensitively, unfolded, or null. */
    public function header(string $name): ?string
    {
        $name = strtolower(trim($name));
        foreach ($this->headers as [$key, $value]) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Every value of a header, in order.
     *
     * @return list<string>
     */
    public function headerAll(string $name): array
    {
        $name = strtolower(trim($name));
        $out = [];
        foreach ($this->headers as [$key, $value]) {
            if (strtolower($key) === $name) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Whether this is a delivery or disposition report (a bounce, a DSN, a read receipt). */
    public function isReport(): bool
    {
        return $this->contentType === 'multipart/report';
    }

    /**
     * Every recipient, envelope first, then To, then Cc, lower-cased and
     * without repeats. Where a plus-address token is looked for.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $all = $this->envelopeTo;
        foreach ([$this->to, $this->cc] as $list) {
            foreach ($list as $address) {
                $all[] = $address->email;
            }
        }

        $out = [];
        foreach ($all as $email) {
            $email = strtolower(trim($email));
            if ($email !== '' && !\in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }
}
