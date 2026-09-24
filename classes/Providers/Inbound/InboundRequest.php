<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * One request that arrived at an inbound-mail address, as plain data.
 *
 * The same idea as {@see \Grav\Plugin\Email\Providers\WebhookRequest}, plus the
 * two things inbound mail needs that delivery webhooks never did: the form
 * fields and the uploaded files of a `multipart/form-data` post. Mailgun and
 * SendGrid post inbound mail that way, and PHP never fills `php://input` for
 * that content type, so for those requests `$body` is `''` and everything is in
 * `$parsedBody` and `$files`.
 *
 * Headers are keyed by their lower-cased name and `$body` is the raw bytes,
 * byte for byte, for the same reason as there: a signature over the body only
 * verifies over exactly the bytes that were signed.
 */
final class InboundRequest
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers     keyed by lower-cased name
     * @param array<string, mixed>  $parsedBody  form fields for multipart and urlencoded posts
     * @param list<InboundUpload>   $files
     */
    public function __construct(
        public readonly string $method = 'POST',
        public readonly string $path = '',
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $parsedBody = [],
        public readonly array $files = [],
        public readonly string $remoteAddress = '',
    ) {
    }

    /**
     * Build one from a PSR-7 server request. The body stream is rewound before
     * and after reading, where it can be.
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

        $parsed = $request->getParsedBody();
        $files = [];
        self::collectPsrFiles($request->getUploadedFiles(), '', $files);
        $server = $request->getServerParams();

        return new self(
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getQueryParams(),
            $headers,
            $body,
            \is_array($parsed) ? $parsed : [],
            $files,
            (string)($server['REMOTE_ADDR'] ?? ''),
        );
    }

    /**
     * Build one from PHP's superglobals, for a consumer that answers the
     * request itself before any framework has run (an early endpoint in
     * `onPluginsInitialized`, say).
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!\is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string)$value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = (string)$value;
            }
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
        $body = @file_get_contents('php://input');

        $files = [];
        self::collectGlobalFiles($_FILES, $files);

        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $body === false ? '' : $body,
            $_POST,
            $files,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /** One header by name, case insensitively. Absent reads as ''. */
    public function header(string $name): string
    {
        return $this->headers[strtolower(trim($name))] ?? '';
    }

    public function hasHeader(string $name): bool
    {
        return \array_key_exists(strtolower(trim($name)), $this->headers);
    }

    /** The media type of the body, lower-cased, without parameters. */
    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type'), 2)[0]));
    }

    /**
     * The body decoded as JSON, or null when it is not JSON.
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

    /** One form field as a string, or '' when absent or not a string. */
    public function field(string $name): string
    {
        $value = $this->parsedBody[$name] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * How many bytes this request carries: the raw body, or for a form post the
     * fields and files together. What the gateway checks against the limit.
     */
    public function size(): int
    {
        $size = \strlen($this->body);
        if ($size > 0) {
            return $size;
        }

        $fields = $this->parsedBody;
        array_walk_recursive($fields, static function ($value) use (&$size): void {
            if (is_scalar($value)) {
                $size += \strlen((string)$value);
            }
        });
        foreach ($this->files as $file) {
            $size += max(0, $file->size);
        }

        return $size;
    }

    /** @param list<InboundUpload> $out */
    private static function collectPsrFiles(array $files, string $prefix, array &$out): void
    {
        foreach ($files as $name => $file) {
            $field = $prefix === '' ? (string)$name : $prefix . '[' . $name . ']';
            if (\is_array($file)) {
                self::collectPsrFiles($file, $field, $out);
                continue;
            }
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }

            $path = '';
            try {
                $uri = $file->getStream()->getMetadata('uri');
                $path = \is_string($uri) ? $uri : '';
            } catch (\Throwable) {
            }

            $out[] = new InboundUpload(
                $field,
                (string)$file->getClientFilename(),
                (string)$file->getClientMediaType(),
                (int)$file->getSize(),
                $path,
                $file->getError(),
            );
        }
    }

    /** @param list<InboundUpload> $out */
    private static function collectGlobalFiles(array $files, array &$out): void
    {
        foreach ($files as $field => $file) {
            if (!\is_array($file) || !isset($file['name'])) {
                continue;
            }
            if (\is_array($file['name'])) {
                foreach ($file['name'] as $i => $name) {
                    $out[] = new InboundUpload(
                        $field . '[' . $i . ']',
                        (string)$name,
                        (string)($file['type'][$i] ?? ''),
                        (int)($file['size'][$i] ?? 0),
                        (string)($file['tmp_name'][$i] ?? ''),
                        (int)($file['error'][$i] ?? 0),
                    );
                }
                continue;
            }
            $out[] = new InboundUpload(
                (string)$field,
                (string)$file['name'],
                (string)($file['type'] ?? ''),
                (int)($file['size'] ?? 0),
                (string)($file['tmp_name'] ?? ''),
                (int)($file['error'] ?? 0),
            );
        }
    }
}
