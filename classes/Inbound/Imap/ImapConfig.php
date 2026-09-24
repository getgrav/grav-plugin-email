<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Inbound\Imap;

/**
 * Where a mailbox is and how to log in to it.
 *
 * `encryption` is `ssl` for TLS from the first byte (port 993, the default),
 * `starttls` for a plain connection upgraded with STARTTLS before login (port
 * 143), or `none` for a local test server only. STARTTLS never falls back to
 * plain text: a server that does not offer it is an error.
 *
 * `auth` is `login` today. `xoauth2` is reserved for `AUTHENTICATE XOAUTH2`
 * (Office 365, and Gmail without an app password); the constant and the branch
 * in {@see ImapMailbox} are the place it goes, with `$password` carrying the
 * access token.
 */
final class ImapConfig
{
    public const SSL = 'ssl';
    public const STARTTLS = 'starttls';
    public const NONE = 'none';

    public const AUTH_LOGIN = 'login';
    public const AUTH_XOAUTH2 = 'xoauth2';

    /**
     * @param array<string, mixed> $sslOptions extra `ssl` stream context options (cafile, …)
     */
    public function __construct(
        public readonly string $host,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly int $port = 993,
        public readonly string $encryption = self::SSL,
        public readonly string $mailbox = 'INBOX',
        /** Seconds, for connecting and for each read. */
        public readonly float $timeout = 30.0,
        public readonly bool $verifyPeer = true,
        public readonly string $auth = self::AUTH_LOGIN,
        public readonly array $sslOptions = [],
    ) {
    }

    /**
     * From a config array: `host`, `port`, `encryption` (`ssl`, `starttls` —
     * `tls` is read as `starttls` — or `none`), `username`, `password`,
     * `mailbox`, `timeout`, `verify_peer`, `auth`. The port defaults to 993
     * for `ssl` and 143 otherwise.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        $encryption = strtolower(trim((string)($config['encryption'] ?? self::SSL)));
        $encryption = match ($encryption) {
            'tls', 'starttls' => self::STARTTLS,
            'none', 'plain', '' => self::NONE,
            default => self::SSL,
        };

        $port = (int)($config['port'] ?? 0);
        if ($port <= 0) {
            $port = $encryption === self::SSL ? 993 : 143;
        }

        $mailbox = trim((string)($config['mailbox'] ?? ''));

        return new self(
            trim((string)($config['host'] ?? '')),
            (string)($config['username'] ?? ''),
            (string)($config['password'] ?? ''),
            $port,
            $encryption,
            $mailbox === '' ? 'INBOX' : $mailbox,
            (float)($config['timeout'] ?? 30.0) ?: 30.0,
            (bool)($config['verify_peer'] ?? true),
            strtolower((string)($config['auth'] ?? self::AUTH_LOGIN)) ?: self::AUTH_LOGIN,
        );
    }

    /**
     * A stable name for this mailbox, for a lease or a stored UID:
     * `user@host:port/mailbox`, lower-cased. Never contains the password.
     */
    public function key(): string
    {
        return strtolower($this->username . '@' . $this->host . ':' . $this->port . '/' . $this->mailbox);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $vars = get_object_vars($this);
        $vars['password'] = $this->password === '' ? '' : '********';

        return $vars;
    }
}
