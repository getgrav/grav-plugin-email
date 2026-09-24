<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * One mailbox from a From, To, Cc or Reply-To header: an address and the
 * display name beside it, both plain UTF-8.
 *
 * Not Symfony's `Mime\Address`, which validates on construction and throws on
 * anything RFC 5322 would not allow. Inbound mail is written by strangers and
 * their software, and a header a strict parser refuses still names who sent the
 * message. This keeps what it can read and never throws; an address it could
 * not read at all is `''`, which {@see isEmpty()} answers.
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }

    /** One address, the first a header names, or an empty one. */
    public static function parse(string $value): self
    {
        return self::parseList($value)[0] ?? new self('');
    }

    /**
     * Every address in an address-list header: groups, quoted names with commas
     * in them, comments, encoded words and bare addresses included.
     *
     * @return list<self>
     */
    public static function parseList(string $value): array
    {
        $out = [];
        foreach (self::split($value) as $piece) {
            $address = self::one($piece);
            if ($address !== null) {
                $out[] = $address;
            }
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->email === '';
    }

    /** The whole address lower-cased, for comparing and looking people up. */
    public function normalized(): string
    {
        return strtolower($this->email);
    }

    /** The part before the last `@`. */
    public function local(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? $this->email : substr($this->email, 0, $at);
    }

    /** The part after the last `@`, lower-cased. */
    public function domain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? '' : strtolower(substr($this->email, $at + 1));
    }

    /**
     * The `+detail` part of the local part (RFC 5233 subaddress), or null.
     * `support+t8f2k@example.com` answers `t8f2k`.
     */
    public function detail(): ?string
    {
        $local = $this->local();
        $plus = strpos($local, '+');

        return $plus === false ? null : substr($local, $plus + 1);
    }

    public function __toString(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        return '"' . addcslashes($this->name, '"\\') . '" <' . $this->email . '>';
    }

    /**
     * Split an address list on the commas that separate addresses, and throw
     * away group syntax (`Team: a@x, b@y;`), which names a group rather than a
     * mailbox.
     *
     * @return list<string>
     */
    private static function split(string $value): array
    {
        $pieces = [];
        $current = '';
        $quoted = false;
        $depth = 0;
        $angle = false;
        $length = \strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];

            if ($quoted) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $current .= $value[++$i];
                } elseif ($c === '"') {
                    $quoted = false;
                }
                continue;
            }

            if ($depth > 0) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $length) {
                    $current .= $value[++$i];
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                }
                continue;
            }

            switch ($c) {
                case '"':
                    $quoted = true;
                    $current .= $c;
                    break;
                case '(':
                    $depth = 1;
                    $current .= $c;
                    break;
                case '<':
                    $angle = true;
                    $current .= $c;
                    break;
                case '>':
                    $angle = false;
                    $current .= $c;
                    break;
                case ':':
                    if ($angle) {
                        $current .= $c;
                    } else {
                        // A group name ends here; what came before it is not an address.
                        $current = '';
                    }
                    break;
                case ',':
                case ';':
                    if ($angle) {
                        $current .= $c;
                    } else {
                        $pieces[] = $current;
                        $current = '';
                    }
                    break;
                default:
                    $current .= $c;
            }
        }
        $pieces[] = $current;

        return array_values(array_filter(array_map('trim', $pieces), static fn (string $p): bool => $p !== ''));
    }

    private static function one(string $piece): ?self
    {
        $comments = [];
        $plain = self::stripComments($piece, $comments);

        if (preg_match('/<([^<>]*)>/', $plain, $m)) {
            $email = trim($m[1]);
            // A source route (<@relay:user@example.com>) is obsolete syntax; keep the mailbox.
            if (str_starts_with($email, '@') && str_contains($email, ':')) {
                $email = substr($email, strpos($email, ':') + 1);
            }
            $name = trim(str_replace($m[0], '', $plain));
            if ($name === '' && $comments !== []) {
                $name = trim($comments[0]);
            }
        } else {
            $email = trim($plain);
            $name = $comments !== [] ? trim($comments[0]) : '';
        }

        $email = trim(self::unquote($email));
        $email = preg_replace('/\s+/', '', $email) ?? $email;
        if ($email === '') {
            return null;
        }

        $name = MimeParser::decodeWords(self::unquote($name));
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return new self($email, $name);
    }

    /** @param list<string> $comments filled with the text of each top-level comment */
    private static function stripComments(string $value, array &$comments): string
    {
        $out = '';
        $comment = '';
        $depth = 0;
        $quoted = false;
        $length = \strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $c = $value[$i];
            if ($depth === 0) {
                if ($quoted) {
                    $out .= $c;
                    if ($c === '\\' && $i + 1 < $length) {
                        $out .= $value[++$i];
                    } elseif ($c === '"') {
                        $quoted = false;
                    }
                } elseif ($c === '"') {
                    $quoted = true;
                    $out .= $c;
                } elseif ($c === '(') {
                    $depth = 1;
                    $comment = '';
                } else {
                    $out .= $c;
                }
                continue;
            }

            if ($c === '\\' && $i + 1 < $length) {
                $comment .= $value[++$i];
            } elseif ($c === '(') {
                $depth++;
                $comment .= $c;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $comments[] = $comment;
                    $out .= ' ';
                } else {
                    $comment .= $c;
                }
            } else {
                $comment .= $c;
            }
        }

        return $out;
    }

    /** Remove the quotes around quoted-strings and the backslashes inside them. */
    private static function unquote(string $value): string
    {
        if (!str_contains($value, '"')) {
            return $value;
        }

        return preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/s',
            static fn (array $m): string => (string)preg_replace('/\\\\(.)/s', '$1', $m[1]),
            $value
        ) ?? $value;
    }
}
