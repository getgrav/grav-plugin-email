<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Inbound\Imap;

/**
 * One message {@see ImapMailbox::fetchNew()} found.
 *
 * `$raw` is the whole message, byte for byte, or null when it was larger than
 * the limit the caller passed and was not downloaded at all. `$size` is the
 * server's `RFC822.SIZE` either way, so a caller can log how large the one it
 * skipped was.
 */
final class ImapItem
{
    public function __construct(
        public readonly int $uid,
        public readonly ?string $raw,
        public readonly int $size,
    ) {
    }

    /** Over the caller's limit and not downloaded. */
    public function isTooLarge(): bool
    {
        return $this->raw === null;
    }
}
