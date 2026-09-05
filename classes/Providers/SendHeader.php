<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

use Grav\Common\Grav;

/**
 * The header a store stamps its send id into, and the reading of it.
 *
 * A store that wants to hear about one particular message puts a header on it
 * and asks the provider to hand that header back. Every provider does it
 * differently — a top-level key, a `{name, value}` list, a user variables map, a
 * metadata object — so the reading is written once here and the seven transport
 * plugins call it instead of each keeping a copy that drifts.
 *
 * ## One name, owned here
 *
 * The name is `X-Grav-Send-Id` and it deliberately says nothing about which
 * add-on is doing the stamping. It used to be spelled `X-KahunaCart-Send`,
 * because KahunaCart's newsletter add-on was the only thing that had ever
 * stamped one, and a Team Grav transport plugin hard-coding another product's
 * name is the sort of thing a merchant reads and does not understand. Whatever
 * stamps it asks {@see name()}, every provider answers {@see name()} from
 * `DeliveryReports::sendHeader()`, and so the two ends cannot disagree about it.
 *
 * Two ways to change it. `plugins.email.providers.send_header` in the site's
 * configuration is the one a merchant has, for a store whose mail already
 * carries a header of its own or whose provider account is shared with
 * something else that would read it. {@see override()} is the one code has, for
 * an add-on that decides the name at runtime rather than in a config file —
 * it wins over the config, and it is how KahunaCart core or the newsletter can
 * set the name for the whole request without writing to anybody's config.
 *
 * A name that is not a legal header name is ignored rather than obeyed, because
 * a header called `Send Id: 41` is not a header, it is a message that stops at
 * the first mail server that reads it.
 *
 * ## What comes back is a string
 *
 * Whatever the store stamped, trimmed, and nothing more. This plugin has no
 * idea what a store's send id looks like and should not pretend to: a store
 * that keeps integer row ids turns it into one at its own end, and a store
 * using a UUID keeps a UUID. What is refused here is only the two things that
 * are never an id — an empty value, and one long enough to be somebody filling
 * a log with it.
 *
 * ## The metadata twin, which is Postmark's fault and nobody else's
 *
 * Postmark returns no headers on any outbound webhook, on any record type, and
 * there is no setting that changes it. What it does return on every one of them
 * is `Metadata`, and the way to attach metadata to a message sent over SMTP is a
 * header named `X-PM-Metadata-<key>`. So a store that wants its events tied to
 * a send on Postmark puts {@see metadataHeader()} on the message beside the
 * ordinary one. {@see metadataKey()} is the header's own name with the leading
 * `X-` taken off and capped at the twenty characters Postmark allows a metadata
 * key.
 */
final class SendHeader
{
    /** The header a store stamps its send id into unless it says otherwise. */
    public const DEFAULT_NAME = 'X-Grav-Send-Id';

    /** Where a site sets its own name. */
    public const CONFIG_KEY = 'plugins.email.providers.send_header';

    /** The prefix that turns a header into Postmark metadata on an SMTP send. */
    public const METADATA_PREFIX = 'X-PM-Metadata-';

    /** Postmark's own cap on the length of a metadata key. */
    public const MAX_METADATA_KEY = 20;

    /** Longer than this is not an id, it is somebody filling a log. */
    public const MAX_LENGTH = 190;

    /**
     * A legal header field name: RFC 7230's token, which is what every mail
     * server and every provider API will accept without an argument.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]{1,190}$/';

    /** Set by {@see override()}, and null when nothing has. */
    private static ?string $override = null;

    private function __construct()
    {
    }

    /**
     * The header name everything on this site uses.
     *
     * The runtime override first, then the site's configuration, then the
     * default. Cheap enough to call per message: it is one config read and a
     * regular expression.
     */
    public static function name(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return self::legalName(self::configured()) ?? self::DEFAULT_NAME;
    }

    /**
     * Name the header for the rest of this request, whatever the config says.
     *
     * For an add-on that decides at runtime. Null puts it back to the config
     * and the default, which is also what an unusable name does — a caller that
     * hands over nonsense gets the working default rather than a store whose
     * mail is refused.
     */
    public static function override(?string $name): void
    {
        self::$override = $name === null ? null : self::legalName($name);
    }

    /**
     * The header's name with its leading `X-` taken off, for a provider that
     * wants a bare key rather than a header.
     *
     * Capped at {@see MAX_METADATA_KEY} because Postmark, the only provider that
     * asks for this, refuses a longer one. `X-Grav-Send-Id` becomes
     * `Grav-Send-Id`, comfortably inside it.
     */
    public static function metadataKey(): string
    {
        $name = self::name();

        if (stripos($name, 'x-') === 0) {
            $name = substr($name, 2);
        }

        return substr($name, 0, self::MAX_METADATA_KEY);
    }

    /**
     * The header that puts {@see metadataKey()} into Postmark's metadata on a
     * message sent over SMTP.
     */
    public static function metadataHeader(): string
    {
        return self::METADATA_PREFIX . self::metadataKey();
    }

    /**
     * A send id out of one value, however the provider typed it.
     *
     * Providers are careless about types — the same field arrives as `"41"`
     * from one and `41` from another — and a send id is one send row either
     * way, so every one of them is read as a string.
     */
    public static function idFrom(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string)$value;
        }

        if (\is_float($value)) {
            return $value === floor($value) && is_finite($value) ? (string)(int)$value : null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > self::MAX_LENGTH ? null : $value;
    }

    /**
     * A send id out of a map keyed by header name — custom args, user
     * variables, a metadata object, an echoed header block.
     *
     * Both spellings are tried, because a provider is free to hand a name back
     * in whatever case it stored it in: Mailgun lower-cases its user variables,
     * SES repeats the header exactly as it was written, and a payload replayed
     * through a tool that lower-cased everything is still the same payload.
     *
     * @param mixed       $map  anything; a non-array answers null
     * @param string|null $name the header to look for, or null for {@see name()}
     */
    public static function idIn(mixed $map, ?string $name = null): ?string
    {
        if (!\is_array($map)) {
            return null;
        }

        $name ??= self::name();

        foreach ([$name, strtolower($name)] as $key) {
            if (!\array_key_exists($key, $map)) {
                continue;
            }

            $id = self::idFrom($map[$key]);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * A send id out of a `{name, value}` header list, the form SES uses.
     *
     * @param mixed       $headers anything; a non-list answers null
     * @param string|null $name    the header to look for, or null for {@see name()}
     */
    public static function idInList(mixed $headers, ?string $name = null): ?string
    {
        return self::idFrom(self::headerInList($headers, $name ?? self::name()));
    }

    /**
     * A send id out of SES message tags, which are `{name: [value]}`.
     *
     * Read because it is the one place a send id survives `headersTruncated`,
     * which is Amazon cutting the header list short once the original headers
     * went over 10 KB.
     *
     * @param mixed       $tags anything; a non-map answers null
     * @param string|null $name the tag to look for, or null for {@see name()}
     */
    public static function idInTags(mixed $tags, ?string $name = null): ?string
    {
        if (!\is_array($tags)) {
            return null;
        }

        $wanted = strtolower(trim($name ?? self::name()));

        foreach ($tags as $tag => $values) {
            if (!\is_string($tag) || strtolower(trim($tag)) !== $wanted) {
                continue;
            }

            $id = self::idFrom(\is_array($values) ? ($values[0] ?? null) : $values);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * One header's value out of a `{name, value}` list, matched case
     * insensitively because a header name is case insensitive on the wire.
     *
     * Not only for the send header: `Message-ID` is read out of the same list
     * by every provider that hands one over that way.
     *
     * @param mixed $headers anything; a non-list answers null
     */
    public static function headerInList(mixed $headers, string $name): ?string
    {
        if (!\is_array($headers)) {
            return null;
        }

        $wanted = strtolower(trim($name));

        foreach ($headers as $header) {
            if (!\is_array($header)) {
                continue;
            }

            if (strtolower(trim((string)($header['name'] ?? ''))) !== $wanted) {
                continue;
            }

            $value = $header['value'] ?? null;

            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                continue;
            }

            $value = trim((string)$value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * What the site's configuration says, or an empty string when nothing does.
     *
     * Guarded, because these classes are read by unit tests and by a CLI
     * process that never booted Grav, and a missing container is "nothing
     * configured" rather than a fatal.
     */
    private static function configured(): string
    {
        if (!class_exists(Grav::class)) {
            return '';
        }

        try {
            $config = Grav::instance()['config'] ?? null;

            return $config === null ? '' : (string)$config->get(self::CONFIG_KEY, '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** The name, trimmed, or null when it is empty or not a legal header name. */
    private static function legalName(string $name): ?string
    {
        $name = trim($name);

        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1 ? $name : null;
    }
}
