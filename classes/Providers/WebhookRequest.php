<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One request that arrived at a delivery-webhook address, as plain data.
 *
 * A provider's `verify()` and `parse()` are pure functions of one of these:
 * no superglobals, no Grav, no session, no framework request object. That is
 * what lets a provider be tested against the sample payloads in its own
 * documentation with nothing else running, which is the only test that catches
 * a provider quietly renaming a field.
 *
 * There are two ways to build one because there are two callers. A plugin that
 * already holds a PSR-7 request — Grav itself, the API plugin, anything on the
 * standard route — uses {@see fromServerRequest()}. A plugin with its own
 * request object of some other kind builds one through the constructor from the
 * parts it has. Both end up in exactly the same state, so a provider only ever
 * sees one thing.
 *
 * ## Headers are lower-cased, always
 *
 * Header names are case insensitive on the wire and every provider spells its
 * own differently — `svix-id`, `X-Twilio-Email-Event-Webhook-Signature`,
 * `X-Mailgun-Signature` — and a proxy in between is free to change the case of
 * any of them. Everything is stored under its lower-cased name and
 * {@see header()} lower-cases what it is asked for, so a provider never has to
 * guess at somebody else's server.
 *
 * ## The body is bytes
 *
 * `$body` is the raw request body exactly as it arrived, byte for byte, and it
 * must stay that way: four of the six signature schemes in use are an HMAC or a
 * public-key signature over the raw bytes, and a body that has been decoded and
 * re-encoded on the way here will not verify however correct the key is.
 * Anything that wants the body as JSON decodes its own copy.
 */
final class WebhookRequest
{
    /**
     * @param string                $method  the HTTP method, upper-cased
     * @param string                $path    the request path, without the query string
     * @param array<string, mixed>  $query   the query string, already parsed
     * @param array<string, string> $headers every header, keyed by its lower-cased name
     * @param string                $body    the raw body, byte for byte
     * @param string                $remoteAddress the caller's address, where the
     *        host resolved one; empty is a normal answer and nothing should
     *        make a decision on it that matters
     */
    public function __construct(
        public readonly string $method = 'POST',
        public readonly string $path = '',
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $remoteAddress = '',
    ) {
    }

    /**
     * Build one from a PSR-7 server request.
     *
     * The body is read from the start of the stream and the stream is rewound
     * afterwards where it can be, so that a caller who reads it again — a log,
     * a second listener — gets the whole body rather than an empty string.
     */
    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        $stream = $request->getBody();

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = (string)$stream;
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        } catch (\Throwable) {
            $body = '';
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string)$name)] = implode(', ', array_map('strval', (array)$values));
        }

        $server = $request->getServerParams();

        return new self(
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getQueryParams(),
            $headers,
            $body,
            (string)($server['REMOTE_ADDR'] ?? ''),
        );
    }

    /** One header by name, case insensitively. Absent reads as an empty string. */
    public function header(string $name): string
    {
        return $this->headers[strtolower(trim($name))] ?? '';
    }

    /** Whether a header arrived at all, which is not the same as it being empty. */
    public function hasHeader(string $name): bool
    {
        return \array_key_exists(strtolower(trim($name)), $this->headers);
    }

    /**
     * The body decoded as JSON, or null when it is not JSON at all.
     *
     * A convenience for the providers that send an object or a list, and
     * deliberately not opinionated about which: SendGrid posts a list where
     * everybody else posts an object, and a helper that answered null for one
     * of those would be a helper the SendGrid provider could not use.
     *
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
