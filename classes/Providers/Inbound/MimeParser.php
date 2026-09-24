<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * Raw RFC 5322 / MIME bytes into an {@see InboundMessage}.
 *
 * Written here rather than bundled because every maintained MIME parser for PHP
 * brings libraries Grav core already ships at its own version (`guzzlehttp/psr7`,
 * `pimple/pimple`, `psr/container`) or a dependency-injection container
 * (`php-di/php-di`), and Grav loads every plugin's autoloader into one process.
 * Reading received mail needs a small, well-known part of MIME, and this is that
 * part with no dependencies beyond mbstring or iconv:
 *
 * - headers, unfolded, with RFC 2047 encoded words decoded where a person reads
 *   them (subject, display names, filenames), adjacent words joined so a
 *   multibyte character split across two of them survives;
 * - multipart nesting of any kind (`mixed`, `alternative`, `related`, `report`,
 *   `signed`, `digest`) to a fixed depth;
 * - quoted-printable and base64, leniently, because real mail gets both wrong;
 * - charsets through mbstring, then iconv, with ISO-8859-1 read as Windows-1252
 *   (what every mail client does, and what makes the curly quotes Outlook puts
 *   in "Latin-1" mail come out right), and invalid UTF-8 repaired rather than
 *   passed on;
 * - `format=flowed` plain text joined back into paragraphs;
 * - attachments with RFC 2231 filenames (`filename*=utf-8''…`, continuations)
 *   and the encoded-word filenames Gmail and Outlook write instead;
 * - inline parts with a `Content-ID`, for `cid:` references in the HTML;
 * - `message/rfc822` parts kept whole as attachments (a forwarded message is an
 *   attachment, not more body), and `multipart/report` recognised as a
 *   delivery report.
 *
 * {@see parse()} never throws. Whatever it is handed, the answer is a message:
 * at worst one with the raw bytes and nothing else read.
 */
final class MimeParser
{
    /** Deeper than any real client nests; anything past this is hostile. */
    private const MAX_DEPTH = 20;

    /** More parts than any real message has. */
    private const MAX_PARTS = 500;

    /** Charset labels that mean something else in practice. */
    private const CHARSET_ALIASES = [
        'latin1' => 'windows-1252',
        'latin-1' => 'windows-1252',
        'iso-8859-1' => 'windows-1252',
        'iso8859-1' => 'windows-1252',
        'iso_8859-1' => 'windows-1252',
        'iso_8859-1:1987' => 'windows-1252',
        'l1' => 'windows-1252',
        'cp819' => 'windows-1252',
        'cp1252' => 'windows-1252',
        'x-cp1252' => 'windows-1252',
        'ansi' => 'windows-1252',
        'utf8' => 'utf-8',
        'unicode-1-1-utf-8' => 'utf-8',
        'us-ascii' => 'utf-8',
        'ascii' => 'utf-8',
        'ansi_x3.4-1968' => 'utf-8',
        'ks_c_5601-1987' => 'cp949',
        'euc-kr' => 'cp949',
        'gb2312' => 'gb18030',
        'gbk' => 'gb18030',
        'x-gbk' => 'gb18030',
        'shift_jis' => 'cp932',
        'shift-jis' => 'cp932',
        'x-sjis' => 'cp932',
        'sjis' => 'cp932',
        'windows-31j' => 'cp932',
        'iso-8859-8-i' => 'iso-8859-8',
        'tis-620' => 'windows-874',
    ];

    /** A few extensions for parts that arrive without a name. */
    private const EXTENSIONS = [
        'text/plain' => 'txt',
        'text/html' => 'html',
        'text/calendar' => 'ics',
        'text/csv' => 'csv',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/ms-tnef' => 'dat',
        'application/pgp-signature' => 'asc',
        'application/pkcs7-signature' => 'p7s',
        'application/x-pkcs7-signature' => 'p7s',
    ];

    private int $partCount = 0;

    /** @var list<string> */
    private array $textParts = [];

    /** @var list<string> */
    private array $htmlParts = [];

    /** @var list<InboundAttachment> */
    private array $attachments = [];

    public function parse(string $raw): InboundMessage
    {
        $this->partCount = 0;
        $this->textParts = [];
        $this->htmlParts = [];
        $this->attachments = [];

        try {
            return $this->read($raw);
        } catch (\Throwable) {
            return new InboundMessage(raw: $raw);
        }
    }

    /**
     * Decode RFC 2047 encoded words (`=?utf-8?B?…?=`, `=?iso-8859-1?Q?…?=`) into
     * UTF-8. Whitespace between two encoded words is dropped, as the RFC says,
     * and adjacent words in one charset are decoded together so a character
     * split across them is not lost. Text that is not UTF-8 is read as
     * Windows-1252. Never throws.
     */
    public static function decodeWords(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::toUtf8($value, 'utf-8');
        }

        $tokens = preg_split(
            '/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)/',
            $value,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($tokens === false) {
            return self::toUtf8($value, 'utf-8');
        }

        $out = '';
        $pendingCharset = null;
        $pendingBytes = '';
        $lastWasWord = false;
        $gap = '';

        $flush = static function () use (&$out, &$pendingCharset, &$pendingBytes): void {
            if ($pendingCharset !== null) {
                $out .= self::toUtf8($pendingBytes, $pendingCharset);
            }
            $pendingCharset = null;
            $pendingBytes = '';
        };

        foreach ($tokens as $token) {
            if (preg_match('/^=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=$/', $token, $m)) {
                $charset = strtolower(explode('*', $m[1], 2)[0]);
                $bytes = strtoupper($m[2]) === 'B'
                    ? (string)base64_decode(self::padBase64($m[3]), false)
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));

                if ($lastWasWord && trim($gap) === '') {
                    $gap = '';
                } elseif ($gap !== '') {
                    $flush();
                    $out .= self::toUtf8($gap, 'utf-8');
                    $gap = '';
                }

                if ($pendingCharset !== null && $pendingCharset !== $charset) {
                    $flush();
                }
                $pendingCharset = $charset;
                $pendingBytes .= $bytes;
                $lastWasWord = true;
                continue;
            }

            if ($lastWasWord && trim($token) === '') {
                // Possibly only the space between two encoded words; decide on the next token.
                $gap = $token;
                continue;
            }

            $flush();
            if ($gap !== '') {
                $out .= self::toUtf8($gap, 'utf-8');
                $gap = '';
            }
            $out .= self::toUtf8($token, 'utf-8');
            $lastWasWord = false;
        }

        $flush();
        if ($gap !== '') {
            $out .= self::toUtf8($gap, 'utf-8');
        }

        return $out;
    }

    /**
     * Bytes in a named charset into valid UTF-8. Unknown charsets and
     * undeclared eight-bit text fall back to Windows-1252, which cannot fail.
     */
    public static function toUtf8(string $bytes, string $charset): string
    {
        if ($bytes === '') {
            return '';
        }

        $charset = strtolower(trim($charset, " \t\"'"));
        $charset = self::CHARSET_ALIASES[$charset] ?? $charset;

        if ($charset === '' || $charset === 'utf-8') {
            if (self::isUtf8($bytes)) {
                return $bytes;
            }
            // Declared UTF-8 (or undeclared) but is not: nearly always Windows-1252.
            $charset = 'windows-1252';
        }

        if ($charset === 'windows-1252' && !preg_match('/[\x80-\xFF]/', $bytes)) {
            return $bytes;
        }

        if (\function_exists('mb_convert_encoding')) {
            try {
                $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
                if (\is_string($converted) && self::isUtf8($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // Not an encoding mbstring knows; try iconv.
            }
        }

        if (\function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
            if (\is_string($converted) && $converted !== '' && self::isUtf8($converted)) {
                return $converted;
            }
        }

        if ($charset !== 'windows-1252') {
            return self::toUtf8($bytes, 'windows-1252');
        }

        return self::scrub($bytes);
    }

    private function read(string $raw): InboundMessage
    {
        $data = str_replace(["\r\n", "\r"], "\n", $raw);
        // An mbox "From " separator line is not a header.
        if (str_starts_with($data, 'From ')) {
            $newline = strpos($data, "\n");
            $data = $newline === false ? '' : substr($data, $newline + 1);
        }

        [$headers, $body] = self::splitEntity($data);
        $this->walk($headers, $body, 0);

        $first = static function (string $name) use ($headers): ?string {
            foreach ($headers as [$key, $value]) {
                if (strtolower($key) === $name) {
                    return $value;
                }
            }

            return null;
        };
        $all = static function (string $name) use ($headers): array {
            $out = [];
            foreach ($headers as [$key, $value]) {
                if (strtolower($key) === $name) {
                    $out[] = $value;
                }
            }

            return $out;
        };

        [$contentType] = self::parseHeaderValue($first('content-type') ?? 'text/plain');

        $envelopeFrom = null;
        $returnPath = $first('return-path');
        if ($returnPath !== null) {
            $envelopeFrom = preg_match('/<([^<>]*)>/', $returnPath, $m) ? trim($m[1]) : trim($returnPath);
        }

        $envelopeTo = [];
        $delivered = $first('x-original-to') ?? $first('delivered-to');
        if ($delivered !== null) {
            foreach (Address::parseList($delivered) as $address) {
                $envelopeTo[] = $address->email;
            }
        }

        $inReplyTo = self::messageIds((string)$first('in-reply-to'));
        $messageId = self::messageIds((string)$first('message-id'));

        return new InboundMessage(
            raw: $raw,
            messageId: $messageId[0] ?? null,
            inReplyTo: $inReplyTo[0] ?? null,
            references: self::messageIds(implode(' ', $all('references'))),
            from: Address::parse((string)$first('from')),
            to: Address::parseList(implode(', ', $all('to'))),
            cc: Address::parseList(implode(', ', $all('cc'))),
            replyTo: Address::parseList(implode(', ', $all('reply-to'))),
            envelopeTo: $envelopeTo,
            envelopeFrom: $envelopeFrom,
            subject: self::oneLine(self::decodeWords((string)$first('subject'))),
            date: self::date($first('date')),
            text: $this->textParts === [] ? null : implode("\n", $this->textParts),
            html: $this->htmlParts === [] ? null : implode("\n", $this->htmlParts),
            headers: $headers,
            attachments: $this->attachments,
            auth: self::authResults($first('authentication-results'), $first('received-spf')),
            spamScore: self::spamScore($first('x-spam-score'), $first('x-spam-status')),
            contentType: $contentType,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    private function walk(array $headers, string $body, int $depth, string $parentType = ''): void
    {
        if (++$this->partCount > self::MAX_PARTS) {
            return;
        }

        $typeHeader = self::headerIn($headers, 'content-type');
        $defaultType = $parentType === 'multipart/digest' ? 'message/rfc822' : 'text/plain';
        [$type, $params] = self::parseHeaderValue($typeHeader ?? $defaultType);
        if ($type === '' || !str_contains($type, '/')) {
            $type = 'text/plain';
        }

        if (str_starts_with($type, 'multipart/') && $depth < self::MAX_DEPTH) {
            $boundary = $params['boundary'] ?? '';
            $parts = $boundary === '' ? null : self::splitMultipart($body, $boundary);
            if ($parts !== null) {
                foreach ($parts as $part) {
                    [$partHeaders, $partBody] = self::splitEntity($part);
                    $this->walk($partHeaders, $partBody, $depth + 1, $type);
                }

                return;
            }
            // A multipart with no parts we can find: read what is there as text.
            $type = 'text/plain';
            $params = [];
        }

        [$disposition, $dispositionParams] = self::parseHeaderValue(
            self::headerIn($headers, 'content-disposition') ?? ''
        );
        $encoding = strtolower(trim((string)self::headerIn($headers, 'content-transfer-encoding')));
        $decoded = self::decodeTransfer($body, $encoding);

        $filename = $dispositionParams['filename'] ?? $params['name'] ?? '';
        $contentId = self::headerIn($headers, 'content-id');
        $contentId = $contentId === null ? null : (trim($contentId, " \t<>") ?: null);

        $isBodyType = $type === 'text/plain' || $type === 'text/html';
        if ($isBodyType && $disposition !== 'attachment' && $filename === '') {
            $text = self::toUtf8($decoded, $params['charset'] ?? '');
            if ($type === 'text/plain') {
                if (strtolower($params['format'] ?? '') === 'flowed') {
                    $text = self::unflow($text, strtolower($params['delsp'] ?? '') === 'yes');
                }
                $this->textParts[] = $text;
            } else {
                $this->htmlParts[] = $text;
            }

            return;
        }

        if ($filename === '') {
            $filename = self::defaultName($type, $decoded, \count($this->attachments) + 1);
        }

        $this->attachments[] = new InboundAttachment(
            self::safeFilename($filename, $type, \count($this->attachments) + 1),
            $type,
            \strlen($decoded),
            $contentId,
            // A forwarded message is never "shown in the body", whatever its disposition says.
            !str_starts_with($type, 'message/')
                && ($disposition === 'inline' || ($disposition === '' && $contentId !== null)),
            $decoded,
        );
    }

    /**
     * Split a header block from its body. Line endings are already `\n`.
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: string}
     */
    private static function splitEntity(string $data): array
    {
        if (str_starts_with($data, "\n")) {
            return [[], substr($data, 1)];
        }

        $end = strpos($data, "\n\n");
        if ($end === false) {
            $firstLine = strtok($data, "\n");
            if ($firstLine !== false && preg_match('/^[!-9;-~]+:/', $firstLine)) {
                return [self::parseHeaders($data), ''];
            }

            return [[], $data];
        }

        return [self::parseHeaders(substr($data, 0, $end)), substr($data, $end + 2)];
    }

    /** @return list<array{0: string, 1: string}> */
    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        $value = '';

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $value .= $line;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            if ($name !== null) {
                $headers[] = [$name, self::toUtf8(trim($value), 'utf-8')];
            }
            $name = rtrim(substr($line, 0, $colon));
            $value = substr($line, $colon + 1);
        }
        if ($name !== null) {
            $headers[] = [$name, self::toUtf8(trim($value), 'utf-8')];
        }

        return $headers;
    }

    /** @param list<array{0: string, 1: string}> $headers */
    private static function headerIn(array $headers, string $name): ?string
    {
        foreach ($headers as [$key, $value]) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The body of a multipart entity, split at its boundary, preamble and
     * epilogue dropped. Null when the boundary never appears.
     *
     * @return list<string>|null
     */
    private static function splitMultipart(string $body, string $boundary): ?array
    {
        $pattern = '/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*$/m';
        if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $parts = [];
        $count = \count($matches);
        for ($i = 0; $i < $count; $i++) {
            if (isset($matches[$i][1]) && $matches[$i][1][0] === '--') {
                break;
            }
            $start = $matches[$i][0][1] + \strlen($matches[$i][0][0]);
            $end = $matches[$i + 1][0][1] ?? \strlen($body);
            $chunk = substr($body, $start, $end - $start);
            if (str_starts_with($chunk, "\n")) {
                $chunk = substr($chunk, 1);
            }
            if (str_ends_with($chunk, "\n")) {
                $chunk = substr($chunk, 0, -1);
            }
            $parts[] = $chunk;
        }

        return $parts;
    }

    /**
     * A structured header value — `text/plain; charset="utf-8"` — as its main
     * value (lower-cased) and its parameters (names lower-cased). RFC 2231
     * extended and continued parameters are combined and decoded, and the
     * encoded words some clients put in quoted filenames are decoded too.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function parseHeaderValue(string $value): array
    {
        $segments = [];
        $current = '';
        $quoted = false;
        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            if ($quoted) {
                if ($c === '\\' && $i + 1 < $length) {
                    $current .= $c . $value[++$i];
                    continue;
                }
                if ($c === '"') {
                    $quoted = false;
                }
                $current .= $c;
                continue;
            }
            if ($c === '"') {
                $quoted = true;
                $current .= $c;
            } elseif ($c === ';') {
                $segments[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }
        $segments[] = $current;

        $main = strtolower(trim((string)preg_replace('/\([^)]*\)/', '', (string)array_shift($segments))));

        $plain = [];
        /** @var array<string, array<int, array{0: bool, 1: string}>> $continued */
        $continued = [];
        foreach ($segments as $segment) {
            $eq = strpos($segment, '=');
            if ($eq === false) {
                continue;
            }
            $name = strtolower(trim(substr($segment, 0, $eq)));
            $raw = trim(substr($segment, $eq + 1));
            if ($name === '') {
                continue;
            }
            if (str_starts_with($raw, '"')) {
                $raw = (string)preg_replace('/\\\\(.)/s', '$1', substr($raw, 1, str_ends_with($raw, '"') && \strlen($raw) > 1 ? -1 : null));
            }

            if (preg_match('/^([^*]+)\*(\d+)(\*)?$/', $name, $m)) {
                $continued[$m[1]][(int)$m[2]] = [isset($m[3]), $raw];
            } elseif (str_ends_with($name, '*')) {
                $continued[substr($name, 0, -1)][0] = [true, $raw];
            } else {
                $plain[$name] = $raw;
            }
        }

        $params = [];
        foreach ($plain as $name => $raw) {
            $params[$name] = ($name === 'filename' || $name === 'name') ? self::decodeWords($raw) : $raw;
        }

        foreach ($continued as $name => $pieces) {
            ksort($pieces);
            $charset = 'utf-8';
            $bytes = '';
            $first = true;
            foreach ($pieces as [$extended, $raw]) {
                if ($extended && $first && substr_count($raw, "'") >= 2) {
                    [$charset, , $raw] = explode("'", $raw, 3);
                    $charset = $charset === '' ? 'utf-8' : $charset;
                }
                $bytes .= $extended ? rawurldecode($raw) : $raw;
                $first = false;
            }
            $params[$name] = self::toUtf8($bytes, $charset);
        }

        return [$main, $params];
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                $clean = (string)preg_replace('/[^A-Za-z0-9+\/]/', '', $body);

                return (string)base64_decode(self::padBase64($clean), false);
            case 'quoted-printable':
                // Some relays pad a soft line break with spaces after the "=".
                $body = (string)preg_replace('/=[ \t]+\n/', "=\n", $body);

                return quoted_printable_decode(str_replace("=\n", '', $body));
            default:
                return $body;
        }
    }

    private static function padBase64(string $value): string
    {
        $value = rtrim($value, '=');
        $remainder = \strlen($value) % 4;
        if ($remainder === 1) {
            $value = substr($value, 0, -1);
        } elseif ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return $value;
    }

    /** RFC 3676 format=flowed: join soft-broken lines back into paragraphs. */
    private static function unflow(string $text, bool $delsp): string
    {
        $out = [];
        $buffer = null;
        $bufferDepth = 0;

        foreach (explode("\n", $text) as $line) {
            preg_match('/^(>*)/', $line, $m);
            $depth = \strlen($m[1]);
            $content = substr($line, $depth);
            if (str_starts_with($content, ' ')) {
                $content = substr($content, 1);
            }

            if ($buffer !== null && $depth !== $bufferDepth) {
                $out[] = self::quoted($bufferDepth, $buffer);
                $buffer = null;
            }

            $soft = str_ends_with($content, ' ') && $content !== '-- ';
            if ($soft && $delsp) {
                $content = substr($content, 0, -1);
            }

            $buffer = ($buffer ?? '') . $content;
            $bufferDepth = $depth;

            if (!$soft) {
                $out[] = self::quoted($depth, $buffer);
                $buffer = null;
            }
        }
        if ($buffer !== null) {
            $out[] = self::quoted($bufferDepth, $buffer);
        }

        return implode("\n", $out);
    }

    private static function quoted(int $depth, string $line): string
    {
        if ($depth === 0) {
            return $line;
        }

        return str_repeat('>', $depth) . ($line === '' ? '' : ' ' . $line);
    }

    private static function defaultName(string $type, string $content, int $index): string
    {
        switch ($type) {
            case 'message/rfc822':
                [$inner] = self::splitEntity(str_replace(["\r\n", "\r"], "\n", $content));
                $subject = self::oneLine(self::decodeWords((string)self::headerIn($inner, 'subject')));

                return ($subject !== '' ? $subject : 'message') . '.eml';
            case 'message/delivery-status':
            case 'message/global-delivery-status':
                return 'delivery-status.txt';
            case 'text/rfc822-headers':
            case 'message/global-headers':
                return 'headers.txt';
            case 'message/disposition-notification':
                return 'disposition-notification.txt';
        }

        $extension = self::EXTENSIONS[$type] ?? 'bin';
        $stem = str_starts_with($type, 'image/') ? 'image' : 'attachment';

        return $stem . '-' . $index . '.' . $extension;
    }

    /** A bare, printable filename: no directories, no control characters, a sane length. */
    private static function safeFilename(string $name, string $type, int $index): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        $name = trim($name, " .\t");
        $name = str_replace(['/', ':', '*', '?', '"', '<', '>', '|'], '_', $name);

        if ($name === '') {
            return self::defaultName($type === 'message/rfc822' ? 'application/octet-stream' : $type, '', $index);
        }

        if (mb_strlen($name) > 180) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 160);
            $name = $extension === '' ? $stem : $stem . '.' . mb_substr($extension, 0, 15);
        }

        return $name;
    }

    /**
     * Every message id in a header, without brackets and lower-cased.
     *
     * @return list<string>
     */
    private static function messageIds(string $value): array
    {
        $ids = [];
        if (preg_match_all('/<([^<>]+)>/', $value, $m)) {
            $ids = $m[1];
        } else {
            foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $token) {
                if (str_contains($token, '@')) {
                    $ids[] = $token;
                }
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $id = strtolower((string)preg_replace('/\s+/', '', $id));
            if ($id !== '' && !\in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private static function date(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim((string)preg_replace('/\([^)]*\)/', '', $value));
        // The day name is informational (RFC 5322 3.3), and PHP reads a wrong one as
        // "the next such weekday", moving the date by up to six days.
        $value = trim((string)preg_replace('/^[A-Za-z]+,?\s*(?=\d)/', '', $value));
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            $time = strtotime($value);

            return $time === false ? null : $time;
        }
    }

    /**
     * The verdicts in the topmost Authentication-Results header (RFC 8601),
     * which is the one the receiving server added. Several DKIM results are
     * common; any pass is a pass.
     *
     * @return array<string, string>
     */
    private static function authResults(?string $results, ?string $receivedSpf): array
    {
        $auth = [];
        if ($results !== null) {
            $clean = (string)preg_replace('/\([^)]*\)/', '', $results);
            $segments = explode(';', $clean);
            // The first segment names the server that checked; Microsoft leaves it out.
            if (!preg_match('/^\s*[a-z-]+\s*=/i', $segments[0])) {
                array_shift($segments);
            }
            foreach ($segments as $segment) {
                if (!preg_match('/^\s*(spf|dkim|dmarc|arc)\s*=\s*([a-z]+)/i', $segment, $m)) {
                    continue;
                }
                $method = strtolower($m[1]);
                $result = strtolower($m[2]);
                if (!isset($auth[$method]) || $result === 'pass') {
                    $auth[$method] = $result;
                }
            }
        }

        if (!isset($auth['spf']) && $receivedSpf !== null && preg_match('/^\s*([a-z]+)/i', $receivedSpf, $m)) {
            $auth['spf'] = strtolower($m[1]);
        }

        return $auth;
    }

    private static function spamScore(?string $score, ?string $status): ?float
    {
        if ($score !== null && preg_match('/-?\d+(?:\.\d+)?/', $score, $m)) {
            return (float)$m[0];
        }
        if ($status !== null && preg_match('/\bscore=(-?\d+(?:\.\d+)?)/i', $status, $m)) {
            return (float)$m[1];
        }

        return null;
    }

    private static function oneLine(string $value): string
    {
        return trim((string)preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    private static function isUtf8(string $bytes): bool
    {
        if (\function_exists('mb_check_encoding')) {
            return mb_check_encoding($bytes, 'UTF-8');
        }

        return preg_match('//u', $bytes) === 1;
    }

    private static function scrub(string $bytes): string
    {
        if (\function_exists('mb_scrub')) {
            return mb_scrub($bytes, 'UTF-8');
        }

        return (string)preg_replace('/[\x80-\xFF]/', '?', $bytes);
    }
}
