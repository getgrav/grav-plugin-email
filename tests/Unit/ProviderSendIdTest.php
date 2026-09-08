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

    /**
     * An SMTP send: the id is in the transcript, because Symfony throws away
     * the line that carries it.
     *
     * `SmtpTransport` reads the server's answer to the message, checks the
     * response code and discards it — but it also appends the whole
     * conversation to the `SentMessage`, so the answer survives. On a
     * provider's own relay that id is the provider's, and for MailerSend it is
     * the only handle a store will ever get: their webhooks carry no headers,
     * no metadata, and not the `Message-ID` either.
     */
    public function testTheIdTheServerQueuedTheMessageUnderIsReadOffTheTranscript(): void
    {
        $transcripts = [
            // MailerSend's own relay.
            "< 250 2.0.0 Ok\r\n> DATA\r\n< 354 End data\r\n< 250 Message queued as 68bf1ca9e0d2f\r\n"
                => '68bf1ca9e0d2f',
            // SMTP2GO and SendGrid both answer this way.
            "< 250 Ok\r\n< 250 2.0.0 Ok: queued as 4hfXtY2Sbpz9vNQX\r\n" => '4hfXtY2Sbpz9vNQX',
            // Exim names it differently.
            "< 250 OK id=1x44gZ-000000006Ap-2AVp\r\n" => '1x44gZ-000000006Ap-2AVp',
        ];

        foreach ($transcripts as $debug => $expected) {
            self::assertSame($expected, $this->idFor('', $debug));
        }
    }

    /**
     * A server that accepted the message without naming it answers null rather
     * than something invented from the line.
     */
    public function testAnAcceptanceWithNoIdInItIsNoId(): void
    {
        self::assertNull($this->idFor('', "< 250 2.0.0 Ok\r\n"));
        self::assertNull($this->idFor('', ''));
    }

    /**
     * The earlier `250`s are answers about an address, not about the message,
     * and one of them can carry an id-looking word.
     */
    public function testTheAnswerAboutTheMessageWinsOverTheAnswersAboutAddresses(): void
    {
        $debug = "> MAIL FROM:<shop@example.com>\r\n< 250 2.1.0 Ok id=not-the-one\r\n"
            . "> RCPT TO:<somebody@example.com>\r\n< 250 2.1.5 Ok\r\n"
            . "> DATA\r\n< 354 End data\r\n< 250 Message queued as the-real-one\r\n";

        self::assertSame('the-real-one', $this->idFor('', $debug));
    }

    /** A transport that named an id of its own is not second-guessed. */
    public function testATransportsOwnIdBeatsTheTranscript(): void
    {
        self::assertSame(
            'from-the-api',
            $this->idFor('from-the-api', "< 250 Message queued as from-the-relay\r\n")
        );
    }

    /** What `idOf()` makes of a transport that answered `$answered`. */
    private function idFor(string $answered, string $debug = ''): ?string
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
        if ($debug !== '') {
            $sent->appendDebug($debug);
        }

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
