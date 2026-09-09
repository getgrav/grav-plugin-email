<?php

declare(strict_types=1);

namespace Grav\Plugin\Email;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The transport a store gets when its configured one could not be built.
 *
 * A transport is built in `onPluginsInitialized`, on every request, before
 * anything has decided whether this request will send an email at all. So a
 * provider plugin that throws while naming its DSN — Mailgun does, when its
 * domain has not been filled in yet — does not produce a failed send. It
 * produces a white screen on every page of the site, the admin included, and
 * the only way back is to hand-edit YAML over SSH. Which is a remarkable
 * punishment for having saved a settings form with one field still empty.
 *
 * So the reason is caught and kept here instead. The site stays up, the form
 * that fixes it stays reachable, and the misconfiguration surfaces at the one
 * moment it is actually relevant: something tried to send.
 *
 * Deliberately not `null://`, which accepts everything and delivers nothing.
 * A store whose transport is broken has to be told so — silently swallowing a
 * customer's order confirmation is the worse of the two failures by a distance.
 */
final class UnusableTransport implements TransportInterface
{
    public function __construct(private readonly string $reason)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException($this->reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function __toString(): string
    {
        return 'unusable://default';
    }
}
