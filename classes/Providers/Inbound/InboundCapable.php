<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Providers\Inbound;

/**
 * A provider that can also receive mail.
 *
 * A separate interface rather than a new method on
 * {@see \Grav\Plugin\Email\Providers\Provider}, because every transport plugin
 * implements `Provider` today and a new method there would be a fatal error in
 * every one of them that had not been updated in the same release. A provider
 * class implements this as well when its provider has an inbound API, and
 * nothing else changes:
 *
 *     final class PostmarkProvider implements Provider, InboundCapable
 *     {
 *         public function inbound(): InboundReceiver
 *         {
 *             return new PostmarkInbound();
 *         }
 *     }
 *
 * {@see InboundGateway} finds it on the provider it already registered on
 * `onEmailProviders`. There is no second event and no second registry.
 */
interface InboundCapable
{
    /** The receiver that reads this provider's inbound webhooks. Cheap: no I/O. */
    public function inbound(): InboundReceiver;
}
