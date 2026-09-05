<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * What this provider needs a sending domain's DNS to say.
 *
 * Every domain check anybody writes is the same question — "does this domain
 * say the right thing about the company actually sending the mail" — and the
 * right thing is different per company. This is the table that turns "the
 * Email plugin is configured with the smtp2go engine" into "the SPF record has
 * to end up sending people to spf.smtp2go.com and the DKIM selector is a CNAME
 * into dkim.smtp2go.net".
 *
 * ## What is deliberately not here
 *
 * The DKIM selector itself. Selectors are per domain and per account — one
 * provider uses `s` plus the account's numeric id, another generates three
 * random tokens, a third uses a date — so there is nothing to look up and
 * nothing to guess, and no way to walk a zone to find one. What is here instead
 * is the *convention*: the zone a selector points into, which is enough to tell
 * a real selector from a name that happens to resolve, and enough to spot the
 * store that changed provider and left last year's record behind.
 *
 * `lookup` is the way out of that for a provider whose API will say. It is
 * handed a domain and answers what the account actually has, and it must never
 * throw: a provider's API being slow or down is an unanswered question, not a
 * broken screen. Where there is no API, it is null and the caller falls back to
 * asking the merchant.
 */
final class DomainFacts
{
    public function __construct(
        /** The SPF include, e.g. 'spf.smtp2go.com', or null when the provider aligns through a return path only. */
        public readonly ?string $spfInclude,
        /** The zone a DKIM CNAME points into, e.g. 'dkim.smtp2go.net', or null when the key is published as TXT. */
        public readonly ?string $dkimZone,
        /** The zone a custom return path CNAME points into, or null. */
        public readonly ?string $returnPathZone,
        /**
         * Reads the account's selectors and return paths for $domain through
         * the API, or answers [] where the API cannot. Never throws.
         *
         * Called as `($lookup)(string $domain): array` and expected to answer
         * `['selectors' => list<string>, 'return_paths' => list<string>]`, with
         * either key absent meaning "could not say".
         */
        public readonly ?\Closure $lookup = null,
    ) {
    }

    /**
     * Ask the API, safely.
     *
     * The "never throws" rule is the provider's to keep, and this is the belt
     * to its braces: a provider that throws anyway costs an empty answer rather
     * than a settings screen with a stack trace on it.
     *
     * @return array{selectors?: list<string>, return_paths?: list<string>}
     */
    public function ask(string $domain): array
    {
        if ($this->lookup === null || trim($domain) === '') {
            return [];
        }

        try {
            $answer = ($this->lookup)(trim($domain));
        } catch (\Throwable) {
            return [];
        }

        return \is_array($answer) ? $answer : [];
    }
}
