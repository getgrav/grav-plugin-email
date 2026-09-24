<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Inbound\Imap;

/**
 * Why an IMAP conversation failed, in one of three kinds a caller acts on
 * differently:
 *
 * - `auth`: the server refused the credentials (or will not take them over an
 *   unencrypted connection). Retrying will not help; a person has to fix the
 *   settings.
 * - `network`: the host could not be reached, TLS could not be set up, a read
 *   timed out, or the server hung up. Worth retrying later.
 * - `protocol`: the server answered something this client did not expect, or
 *   refused a command (`NO`/`BAD`) other than the login.
 *
 * Messages are written for a site owner's log. None of them ever contains the
 * password.
 */
final class ImapException extends \RuntimeException
{
    public const AUTH = 'auth';
    public const NETWORK = 'network';
    public const PROTOCOL = 'protocol';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function auth(string $message): self
    {
        return new self(self::AUTH, $message);
    }

    public static function network(string $message, ?\Throwable $previous = null): self
    {
        return new self(self::NETWORK, $message, $previous);
    }

    public static function protocol(string $message): self
    {
        return new self(self::PROTOCOL, $message);
    }

    public function isAuth(): bool
    {
        return $this->kind === self::AUTH;
    }

    public function isNetwork(): bool
    {
        return $this->kind === self::NETWORK;
    }

    public function isProtocol(): bool
    {
        return $this->kind === self::PROTOCOL;
    }
}
