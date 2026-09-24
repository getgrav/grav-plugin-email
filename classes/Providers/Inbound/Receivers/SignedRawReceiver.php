<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound\Receivers;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Verdict;

/**
 * The raw-MIME scheme the built-in receivers share: the whole message as the
 * request body, signed end to end with a secret only the site and the sender
 * know.
 *
 *     POST {webhook url}
 *     Content-Type: message/rfc822
 *     X-Grav-Envelope-To: support+t8f2k@example.com
 *     X-Grav-Envelope-From: customer@example.net
 *     X-Grav-Signature: t=1758650000,v1=5f2b…
 *
 *     {the message, byte for byte}
 *
 * `v1` is the lower-case hex HMAC-SHA256 of `t . "." . body` with the shared
 * secret, where `t` is the Unix time the sender signed at. It is checked over
 * the raw body before anything reads it, with `hash_equals`, and refused when
 * `t` is more than the tolerance (300 seconds by default) away from now, so a
 * captured request cannot be replayed later. Several `v1=` values are allowed
 * in one header, and any match passes, so a secret can be rotated without a
 * gap.
 *
 * The envelope headers are not part of the signature. They carry what the
 * sender's side saw at SMTP time (the plus address survives there when the
 * visible To has lost it), the request travels over HTTPS, and a replay inside
 * the tolerance window still needs the exact signed body, which a consumer's
 * dedupe key catches.
 *
 * ## Config
 *
 * The consumer passes these in `$config`; none of them live in a transport
 * plugin, because these receivers are not tied to one.
 *
 * - `secret` (string, required): the shared secret. Fewer than 32 characters is
 *   refused as unconfigured, not treated as a weak pass.
 * - `tolerance` (int, seconds, optional, default 300).
 * - `max_bytes` (int, optional): read by the gateway, which answers 413 above it
 *   or above {@see maxBytes()}, whichever is smaller.
 */
abstract class SignedRawReceiver implements InboundReceiver
{
    public const SIGNATURE_HEADER = 'X-Grav-Signature';
    public const ENVELOPE_TO_HEADER = 'X-Grav-Envelope-To';
    public const ENVELOPE_FROM_HEADER = 'X-Grav-Envelope-From';

    public const DEFAULT_TOLERANCE = 300;
    public const MIN_SECRET_LENGTH = 32;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock the current Unix time; tests pass their own */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The header value a sender puts on a request, for tests, the docs and any
     * PHP sender.
     */
    public static function sign(string $body, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
    }

    public function verificationKeys(): array
    {
        return ['secret'];
    }

    public function maxBytes(): int
    {
        return 25 * 1024 * 1024;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $secret = \is_string($config['secret'] ?? null) ? $config['secret'] : '';
        if (\strlen($secret) < self::MIN_SECRET_LENGTH) {
            return Verdict::refused(sprintf(
                'The %s receiver has no signing secret of at least %d characters configured, so nothing can be verified.',
                $this->key(),
                self::MIN_SECRET_LENGTH
            ));
        }

        $header = $request->header(self::SIGNATURE_HEADER);
        if ($header === '') {
            return Verdict::refused('The request carried no X-Grav-Signature header.');
        }

        $time = null;
        $signatures = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(substr($pair, 0, $eq));
            $value = trim(substr($pair, $eq + 1));
            if ($name === 't' && ctype_digit($value)) {
                $time = (int)$value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($time === null || $signatures === []) {
            return Verdict::refused('The X-Grav-Signature header is not in the form t={unix},v1={hex}.');
        }

        $tolerance = (int)($config['tolerance'] ?? self::DEFAULT_TOLERANCE);
        if ($tolerance <= 0) {
            $tolerance = self::DEFAULT_TOLERANCE;
        }
        $now = ($this->clock)();
        if (abs($now - $time) > $tolerance) {
            return Verdict::refused(sprintf(
                'The signature timestamp is %d seconds from this server\'s clock, more than the %d allowed. '
                . 'Either the request is a replay or one of the two clocks is wrong.',
                abs($now - $time),
                $tolerance
            ));
        }

        $expected = hash_hmac('sha256', $time . '.' . $request->body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return Verdict::verified();
            }
        }

        return Verdict::refused('The signature does not match the body. The secret differs, or the body was changed on the way.');
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            $body = $request->body;
            if (trim($body) === '') {
                return InboundPayload::unreadable('The request body was empty; a raw message was expected.');
            }
            if (!preg_match('/^(?:From |[A-Za-z0-9-]+:)/', ltrim($body))) {
                return InboundPayload::unreadable('The request body does not start with a mail header; a raw message was expected.');
            }

            $overrides = [];
            if ($request->hasHeader(self::ENVELOPE_TO_HEADER)) {
                $to = [];
                foreach (Address::parseList($request->header(self::ENVELOPE_TO_HEADER)) as $address) {
                    $to[] = $address->email;
                }
                if ($to !== []) {
                    $overrides['envelopeTo'] = $to;
                }
            }
            if ($request->hasHeader(self::ENVELOPE_FROM_HEADER)) {
                $from = trim($request->header(self::ENVELOPE_FROM_HEADER), " \t<>");
                $overrides['envelopeFrom'] = $from;
            }

            return InboundPayload::of([InboundMessage::fromMime($body, $this->key(), $overrides)]);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('The message could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Never called: this receiver always delivers the whole message.
     *
     * @throws \LogicException
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        throw new \LogicException(sprintf('The %s receiver delivers whole messages and has nothing to fetch.', $this->key()));
    }
}
