<?php

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * The provider's own id for a message just sent, and the one case that has to
 * answer null.
 *
 * Symfony's transports all call `SentMessage::setMessageId()`, but they do not
 * agree on what they put there: an API transport that mints its own id puts
 * that, and one whose API simply echoes the message back puts the id the
 * message already had. The second is not a provider id, and storing it as one
 * fills the column that exists for matching delivery reports with a string no
 * delivery report will ever name.
 */
final class ProviderSendIdTest extends TestCase
{
    private const OURS = 'nl-12-9-58a9522365aa2ce2@shop.example.com';

    /**
     * The bug this test exists for, found on a real Mailgun send.
     *
     * Mailgun answers with the message's own id in its wire form — angle
     * brackets and all — and only one side of the comparison was being
     * unwrapped, so `<nl-12-9-…>` did not look equal to `nl-12-9-…`. The store
     * then recorded its own Message-ID as Mailgun's id for the message, and
     * the event that arrived named a third string again, so the rung of the
     * ladder that is supposed to be the most reliable matched nothing.
     */
    public function testAnEchoedMessageIdIsNotAProviderId(): void
    {
        self::assertNull($this->idFor('<' . self::OURS . '>'), 'the same id, in the wire form a header uses');
        self::assertNull($this->idFor(self::OURS), 'and the same id bare');
        self::assertNull($this->idFor(' <' . self::OURS . '> '), 'and with whitespace round it');
    }

    /** A provider that mints its own is the case the column exists for. */
    public function testAProvidersOwnIdComesBack(): void
    {
        self::assertSame(
            '23e00073-7c45-4eff-a1cf-ee48d8cf815c',
            $this->idFor('23e00073-7c45-4eff-a1cf-ee48d8cf815c')
        );
    }

    public function testATransportThatNamesNothingAnswersNull(): void
    {
        self::assertNull($this->idFor(''));
        self::assertNull($this->idFor('   '));
    }

    /** What `idOf()` makes of a transport that answered `$answered`. */
    private function idFor(string $answered): ?string
    {
        $message = (new MimeEmail())
            ->from('shop@example.com')
            ->to('somebody@example.com')
            ->subject('Your order')
            ->text('Thanks.');
        $message->getHeaders()->addIdHeader('Message-ID', self::OURS);

        $sent = new SentMessage($message, new \Symfony\Component\Mailer\Envelope(
            new \Symfony\Component\Mime\Address('shop@example.com'),
            [new \Symfony\Component\Mime\Address('somebody@example.com')]
        ));
        $sent->setMessageId($answered);

        $email = new class extends Email {
            public function __construct()
            {
            }

            public function idFor(SentMessage $sent): ?string
            {
                return $this->idOf($sent);
            }
        };

        return $email->idFor($sent);
    }
}
