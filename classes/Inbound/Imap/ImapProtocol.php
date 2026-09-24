<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Inbound\Imap;

/**
 * The wire half of the IMAP client: one socket, tagged commands, and responses
 * read with their literals (RFC 9051 / RFC 3501).
 *
 * Pure PHP over `stream_socket_client`, because ext/imap left PHP core in 8.4
 * and was never on most hosts anyway. It knows nothing about mailboxes;
 * {@see ImapMailbox} does. What it does:
 *
 * - connects with implicit TLS, STARTTLS (never falling back to plain text), or
 *   plain for a local test server, and verifies the certificate by default;
 * - sends a command built of text and literals, waiting for the server's `+`
 *   continuation before each literal (or not, where the server offers
 *   `LITERAL+`);
 * - reads responses line by line, and a `{n}` at the end of a line as n bytes
 *   of literal that follow, so a message body with any bytes in it at all comes
 *   through untouched;
 * - collects untagged responses until the tagged one arrives, so `EXISTS`,
 *   `EXPUNGE` and `FETCH FLAGS` noise from other clients never confuses the
 *   answer to the command that was sent.
 *
 * A response line is answered as `['text' => string, 'literals' => list<string>]`:
 * the line with each literal left as its `{n}` marker, and the literal bytes in
 * order beside it.
 */
final class ImapProtocol
{
    /** @var resource|null */
    private $stream;

    private int $tag = 0;

    /** @var list<string> upper-cased */
    private array $capabilities = [];

    private bool $loggingOut = false;

    /** @param resource $stream */
    private function __construct($stream, private readonly ImapConfig $config)
    {
        $this->stream = $stream;
    }

    /**
     * Connect, read the greeting, set up TLS as configured, and learn the
     * server's capabilities.
     *
     * @throws ImapException
     */
    public static function open(ImapConfig $config): self
    {
        if ($config->host === '') {
            throw ImapException::network('No IMAP host is configured.');
        }

        $ssl = $config->sslOptions + [
            'peer_name' => $config->host,
            'verify_peer' => $config->verifyPeer,
            'verify_peer_name' => $config->verifyPeer,
            'allow_self_signed' => !$config->verifyPeer,
            'SNI_enabled' => true,
        ];
        $context = stream_context_create(['ssl' => $ssl]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'tcp://' . $config->host . ':' . $config->port,
            $errno,
            $errstr,
            $config->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw ImapException::network(sprintf(
                'Could not connect to %s:%d: %s',
                $config->host,
                $config->port,
                $errstr !== '' ? $errstr : 'error ' . $errno
            ));
        }

        self::applyTimeout($stream, $config->timeout);
        $protocol = new self($stream, $config);

        try {
            if ($config->encryption === ImapConfig::SSL) {
                $protocol->enableTls();
            }

            $greeting = $protocol->readLine();
            if (preg_match('/^\* BYE\b/i', $greeting['text'])) {
                throw ImapException::network('The server refused the connection: ' . self::clip($greeting['text']));
            }
            if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting['text'])) {
                throw ImapException::protocol('The server did not answer with an IMAP greeting: ' . self::clip($greeting['text']));
            }
            $protocol->learnCapabilities($greeting['text']);

            if ($config->encryption === ImapConfig::STARTTLS) {
                if ($protocol->capabilities === []) {
                    $protocol->refreshCapabilities();
                }
                if (!$protocol->hasCapability('STARTTLS')) {
                    throw ImapException::network(
                        'The server does not offer STARTTLS, so the connection cannot be encrypted. '
                        . 'Use implicit TLS on port 993 instead.'
                    );
                }
                $protocol->command(['STARTTLS']);
                $protocol->enableTls();
                // Capabilities learned before TLS must not be trusted after it (RFC 9051 6.2.1).
                $protocol->capabilities = [];
            }

            if ($protocol->capabilities === []) {
                $protocol->refreshCapabilities();
            }
        } catch (\Throwable $e) {
            $protocol->disconnect();
            throw $e instanceof ImapException ? $e : ImapException::network($e->getMessage(), $e);
        }

        return $protocol;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function hasCapability(string $name): bool
    {
        return \in_array(strtoupper($name), $this->capabilities, true);
    }

    /** @throws ImapException */
    public function refreshCapabilities(): void
    {
        $response = $this->command(['CAPABILITY']);
        foreach ($response['untagged'] as $line) {
            if (preg_match('/^\* CAPABILITY\b/i', $line['text'])) {
                $this->capabilities = self::capabilityList(substr($line['text'], 13));
            }
        }
        $this->learnCapabilities($response['text']);
    }

    /**
     * An argument as an IMAP string: quoted when that is safe, a literal when
     * the value has line breaks, NUL or eight-bit bytes in it.
     *
     * @return string|array{literal: string}
     */
    public static function astring(#[\SensitiveParameter] string $value): string|array
    {
        if (preg_match('/[\x00\r\n\x80-\xFF]/', $value)) {
            return ['literal' => $value];
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * Send a tagged command and read until its tagged answer.
     *
     * `$parts` are joined with single spaces; an `['literal' => bytes]` part is
     * sent as a literal. A `NO` or `BAD` answer throws a protocol error whose
     * message is the server's text; the response code (`TRYCREATE`,
     * `AUTHENTICATIONFAILED`, …) is on the answer as `code` for a caller that
     * catches it via {@see tryCommand()}.
     *
     * @param list<string|array{literal: string}> $parts
     *
     * @return array{status: string, code: string, text: string, untagged: list<array{text: string, literals: list<string>}>}
     *
     * @throws ImapException
     */
    public function command(array $parts): array
    {
        $response = $this->tryCommand($parts);
        if ($response['status'] !== 'OK') {
            throw ImapException::protocol(sprintf(
                '%s was refused by the server: %s',
                self::verb($parts),
                self::clip($response['text'])
            ));
        }

        return $response;
    }

    /**
     * {@see command()} without throwing on `NO` or `BAD`; still throws on a
     * broken connection.
     *
     * @param list<string|array{literal: string}> $parts
     *
     * @return array{status: string, code: string, text: string, untagged: list<array{text: string, literals: list<string>}>}
     *
     * @throws ImapException
     */
    public function tryCommand(array $parts): array
    {
        $tag = sprintf('G%04d', ++$this->tag);
        $this->loggingOut = strtoupper(self::verb($parts)) === 'LOGOUT';
        $literalPlus = $this->hasCapability('LITERAL+');

        $buffer = $tag;
        foreach ($parts as $part) {
            if (\is_array($part)) {
                $bytes = $part['literal'];
                $this->write($buffer . ' {' . \strlen($bytes) . ($literalPlus ? '+' : '') . "}\r\n");
                if (!$literalPlus) {
                    $this->awaitContinuation();
                }
                $this->write($bytes);
                $buffer = '';
                continue;
            }
            $buffer .= ' ' . $part;
        }
        $this->write($buffer . "\r\n");

        $untagged = [];
        while (true) {
            $line = $this->readLine();
            $text = $line['text'];

            if (str_starts_with($text, $tag . ' ')) {
                $rest = substr($text, \strlen($tag) + 1);
                $status = strtoupper((string)strtok($rest, ' '));
                $code = preg_match('/^\S+\s+\[([^\]]*)\]/', $rest, $m) ? $m[1] : '';
                if ($status === 'OK') {
                    $this->learnCapabilities($rest);
                }

                return ['status' => $status, 'code' => $code, 'text' => $rest, 'untagged' => $untagged];
            }

            if (preg_match('/^\* BYE\b/i', $text) && !$this->loggingOut) {
                $this->disconnect();
                throw ImapException::network('The server closed the connection: ' . self::clip($text));
            }

            if (str_starts_with($text, '* ')) {
                $untagged[] = $line;
            }
            // A stray continuation or anything else is ignored.
        }
    }

    /**
     * One response line, with any literals it carries read in full.
     *
     * @return array{text: string, literals: list<string>}
     *
     * @throws ImapException
     */
    public function readLine(): array
    {
        $text = '';
        $literals = [];

        while (true) {
            $chunk = $this->readRawLine();
            if (preg_match('/\{(\d+)\+?\}$/', $chunk, $m)) {
                $text .= $chunk;
                $literals[] = $this->readBytes((int)$m[1]);
                continue;
            }
            $text .= $chunk;

            return ['text' => $text, 'literals' => $literals];
        }
    }

    public function isOpen(): bool
    {
        return \is_resource($this->stream);
    }

    /** Close the socket without saying goodbye. Never throws. */
    public function disconnect(): void
    {
        if (\is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    private function awaitContinuation(): void
    {
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line['text'], '+')) {
                return;
            }
            if (preg_match('/^\S+ (NO|BAD)\b/i', $line['text']) && !str_starts_with($line['text'], '* ')) {
                throw ImapException::protocol('The server refused a literal: ' . self::clip($line['text']));
            }
        }
    }

    private function enableTls(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $ok = stream_socket_enable_crypto($this->stream, true, $method);
        } finally {
            restore_error_handler();
        }

        if ($ok !== true) {
            throw ImapException::network(sprintf(
                'TLS could not be set up with %s: %s',
                $this->config->host,
                $error !== null ? preg_replace('/^stream_socket_enable_crypto\(\): /', '', $error) : 'the handshake failed'
            ));
        }
    }

    private function write(string $bytes): void
    {
        if (!\is_resource($this->stream)) {
            throw ImapException::network('The IMAP connection is closed.');
        }

        $length = \strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($this->stream, substr($bytes, $written));
            if ($n === false || $n === 0) {
                $this->failRead('writing');
            }
            $written += $n;
        }
    }

    private function readRawLine(): string
    {
        if (!\is_resource($this->stream)) {
            throw ImapException::network('The IMAP connection is closed.');
        }

        $line = '';
        while (!str_ends_with($line, "\n")) {
            $chunk = @fgets($this->stream, 8192);
            if ($chunk === false) {
                $this->failRead('reading');
            }
            $line .= $chunk;
            if (\strlen($line) > 1024 * 1024) {
                throw ImapException::protocol('The server sent a response line over 1 MB long.');
            }
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $count): string
    {
        if (!\is_resource($this->stream)) {
            throw ImapException::network('The IMAP connection is closed.');
        }

        $bytes = '';
        while (\strlen($bytes) < $count) {
            $chunk = @fread($this->stream, min(65536, $count - \strlen($bytes)));
            if ($chunk === false || $chunk === '') {
                $this->failRead('reading a literal from');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function failRead(string $doing): never
    {
        $timedOut = \is_resource($this->stream) && (stream_get_meta_data($this->stream)['timed_out'] ?? false);
        $this->disconnect();

        if ($timedOut) {
            throw ImapException::network(sprintf(
                'Timed out after %s seconds %s %s.',
                rtrim(rtrim(number_format($this->config->timeout, 1, '.', ''), '0'), '.'),
                $doing,
                $this->config->host
            ));
        }

        throw ImapException::network(sprintf('The connection to %s was lost while %s it.', $this->config->host, $doing));
    }

    /** Pick up a `[CAPABILITY …]` response code wherever the server volunteers one. */
    private function learnCapabilities(string $text): void
    {
        if (preg_match('/\[CAPABILITY ([^\]]*)\]/i', $text, $m)) {
            $this->capabilities = self::capabilityList($m[1]);
        }
    }

    /** @return list<string> */
    private static function capabilityList(string $text): array
    {
        return array_values(array_filter(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            preg_split('/\s+/', trim($text)) ?: []
        ), static fn (string $c): bool => $c !== ''));
    }

    /** @param resource $stream */
    private static function applyTimeout($stream, float $timeout): void
    {
        $seconds = (int)floor($timeout);
        $micro = (int)(($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, max(0, $seconds), max(0, $micro));
    }

    /** @param list<string|array{literal: string}> $parts */
    private static function verb(array $parts): string
    {
        $first = $parts[0] ?? '';
        if (!\is_string($first)) {
            return 'The command';
        }
        $words = explode(' ', $first);
        if (strtoupper($words[0]) === 'UID' && isset($words[1])) {
            return 'UID ' . strtoupper($words[1]);
        }

        return strtoupper($words[0]);
    }

    private static function clip(string $text): string
    {
        $text = trim($text);

        return \strlen($text) > 300 ? substr($text, 0, 300) . '…' : $text;
    }
}
