<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * One file that arrived in a multipart form post.
 *
 * Mailgun and SendGrid post inbound mail as `multipart/form-data`, and PHP
 * never fills `php://input` for that content type, so the parts arrive here
 * rather than in {@see InboundRequest::$body}. The file itself stays where PHP
 * put it; {@see contents()} reads it when something needs the bytes.
 */
final class InboundUpload
{
    public function __construct(
        /** The form field name, e.g. `attachment-1` or `email`. */
        public readonly string $field,
        /** The filename the sender gave, unsanitised. Never trust it as a path. */
        public readonly string $filename,
        /** The content type the sender gave. */
        public readonly string $type,
        public readonly int $size,
        /** Where PHP stored it for this request. */
        public readonly string $tmpPath,
        /** PHP's UPLOAD_ERR_* code; 0 is a good upload. */
        public readonly int $error = 0,
    ) {
    }

    /** The file's bytes, or null when it did not arrive or cannot be read. */
    public function contents(): ?string
    {
        if ($this->error !== 0 || $this->tmpPath === '' || !is_file($this->tmpPath)) {
            return null;
        }

        $bytes = @file_get_contents($this->tmpPath);

        return $bytes === false ? null : $bytes;
    }
}
