<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * One attachment or inline part of an inbound message.
 *
 * The bytes are either in `$content` (decoded from the MIME, or from a
 * provider's base64 field) or in a file at `$path` (a multipart upload PHP
 * already wrote to disk); {@see bytes()} answers them either way. Exactly one
 * of the two is set.
 *
 * `$filename` has been reduced to a bare name — no directories, no control
 * characters — but it is still the sender's choice and a consumer checks the
 * extension against its own allowlist before storing anything. `$size` is the
 * decoded size in bytes.
 *
 * `$inline` means the part was meant to be shown in the body rather than
 * listed: `Content-Disposition: inline`, or a `Content-ID` with no disposition
 * at all, which is how Apple Mail and Outlook mark a pasted image. The HTML
 * refers to it as `cid:{$contentId}`. A forwarded message
 * (`message/rfc822`) arrives as an attachment with its raw bytes as content
 * and is never unpacked into the outer message's body.
 */
final class InboundAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $contentType,
        public readonly int $size,
        /** The Content-ID without angle brackets, or null. */
        public readonly ?string $contentId = null,
        public readonly bool $inline = false,
        public readonly ?string $content = null,
        public readonly ?string $path = null,
    ) {
    }

    /** The decoded bytes, from memory or from disk; '' when neither can be read. */
    public function bytes(): string
    {
        if ($this->content !== null) {
            return $this->content;
        }
        if ($this->path !== null && is_file($this->path)) {
            $bytes = @file_get_contents($this->path);

            return $bytes === false ? '' : $bytes;
        }

        return '';
    }

    /** The extension of the filename, lower-cased, without the dot. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }
}
