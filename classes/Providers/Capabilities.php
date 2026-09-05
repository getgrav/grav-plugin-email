<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers;

/**
 * What a transport does to a message on its way out, in the three ways that
 * decide whether a bulk sender's mail is any good.
 *
 * This is not a feature list. Every one of these three is a thing that is
 * invisible when it goes wrong: a store sets `List-Unsubscribe`, the transport
 * turns the message into a JSON body and drops every header it does not
 * recognise, the mail goes out looking fine, and a year later Gmail is filing
 * it as spam because a bulk sender with no unsubscribe button is what a
 * spammer looks like. Nothing on any screen would say so. Answering it here is
 * what lets a screen say so.
 *
 * - **`customHeaders`** — an `X-` header a plugin sets on the message reaches
 *   the wire. True for every transport that sends over SMTP, because there the
 *   headers *are* the first half of the message. False for an API transport
 *   that has not been taught to carry them.
 * - **`unsubscribeHeaders`** — `List-Unsubscribe` and `List-Unsubscribe-Post`
 *   survive. Usually the same answer as the one above, and asked separately
 *   because some API transports carry a named allowlist of headers with those
 *   two on it and nothing else.
 * - **`echoesHeaders`** — the provider hands a registered custom header back in
 *   its delivery webhooks, which is what lets a bounce be tied to the exact
 *   message it came from. Several do only once the header has been registered
 *   in the provider's own dashboard or through its API, and one — Postmark —
 *   never echoes a header at all and hands back metadata instead. `echoNote`
 *   is where that is said, in plain words, for a screen to show beside the
 *   answer.
 * - **`signsWebhooks`** — optional, for the one case where "has a verification
 *   key" and "signs" disagree: SES signs every notification with a published
 *   certificate and asks the merchant for nothing, so its answer is true with
 *   no key. Left null, a screen derives it from the keys.
 */
final class Capabilities
{
    public function __construct(
        /** Custom headers set on the message reach the wire. */
        public readonly bool $customHeaders,
        /** List-Unsubscribe and List-Unsubscribe-Post survive. */
        public readonly bool $unsubscribeHeaders,
        /** The provider echoes a registered custom header back in its webhooks, and what must be done for that. */
        public readonly bool $echoesHeaders,
        public readonly string $echoNote = '',
        /**
         * Whether the provider signs its webhooks, for a screen that says so.
         * Null means "work it out from DeliveryReports::verificationKeys()":
         * a provider with a key signs, one with none does not. Set it only
         * where that rule is wrong, as for SES, which signs every message with
         * a certificate it publishes and needs no key from the merchant.
         */
        public readonly ?bool $signsWebhooks = null,
    ) {
    }
}
