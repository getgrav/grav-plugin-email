<?php

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\UnusableTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * The transport a store gets when the configured one could not be built.
 *
 * The bug behind it, found by saving the Mailgun settings form with the domain
 * still empty: the transport is built in `onPluginsInitialized`, so a provider
 * plugin that throws while naming its DSN white-screens every page of the site
 * — including the settings form where the domain would have been typed.
 */
final class UnusableTransportTest extends TestCase
{
    public function testSendingThroughItFailsWithTheReasonRatherThanSilently(): void
    {
        $transport = new UnusableTransport('The mailgun transport could not be set up: requires a domain.');

        $message = (new MimeEmail())
            ->from('shop@example.com')
            ->to('somebody@example.com')
            ->subject('Your order')
            ->text('Thanks.');

        try {
            (new Mailer($transport))->send($message);
            self::fail('a broken transport must refuse the message rather than accept and drop it');
        } catch (TransportExceptionInterface $e) {
            self::assertStringContainsString('requires a domain', $e->getMessage());
        }
    }

    /**
     * The distinction the class exists for. `null://` accepts everything and
     * delivers nothing, which on a store means an order confirmation that was
     * never sent and nothing anywhere saying so.
     */
    public function testItIsNotANullTransport(): void
    {
        $transport = new UnusableTransport('nope');

        self::assertNotSame('null://default', (string)$transport);
        self::assertSame('nope', $transport->reason());
    }
}
