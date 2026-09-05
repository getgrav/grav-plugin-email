<?php

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Message;
use PHPUnit\Framework\TestCase;

/**
 * The `headers` parameter, which is what `buildMessage()` hands to
 * {@see Email::applyHeaders()} once everything else is on the message.
 *
 * Every assertion here reads the header back off a real Symfony message rather
 * than off the array it came from, because the whole point of the parameter is
 * that a caller no longer has to know how Symfony wants a given header written.
 */
final class HeadersParameterTest extends TestCase
{
    /**
     * An `Email` that does not need Grav.
     *
     * The constructor builds a transport and opens a log file, neither of which
     * a unit test wants, and `logHeaderSkipped()` writes to both this plugin's
     * log and Grav's. Both are replaced, and every skip is collected so a test
     * can assert the header was dropped *and* that somebody was told.
     */
    private function email(): Email
    {
        return new class extends Email {
            /** @var array */
            public $skipped = [];

            public function __construct()
            {
            }

            protected function logHeaderSkipped($name, string $reason): void
            {
                $this->skipped[] = ['name' => $name, 'reason' => $reason];
            }
        };
    }

    /** The header lines a message would go out with, in wire form. */
    private function lines(Message $message): array
    {
        return $message->getEmail()->getHeaders()->toArray();
    }

    public function testTheOneClickUnsubscribePairGoesOnTheMessage(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), [
            'List-Unsubscribe' => '<mailto:leave@example.com>, <https://example.com/newsletter/u/abc>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        // Read as a body rather than a wire line: the pair is over 76 characters
        // and Symfony folds it after the comma, which is correct and is not what
        // this test is about.
        $this->assertSame(
            '<mailto:leave@example.com>, <https://example.com/newsletter/u/abc>',
            $message->getEmail()->getHeaders()->get('List-Unsubscribe')->getBody()
        );
        $this->assertContains('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $this->lines($message));
        $this->assertSame([], $email->skipped);
    }

    public function testTheMessageIsReturnedForChaining(): void
    {
        $message = new Message();

        $this->assertSame($message, $this->email()->applyHeaders($message, ['Precedence' => 'bulk']));
    }

    public function testAnInvalidHeaderNameIsSkippedAndLogged(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), [
            'X Broken Name' => 'no spaces allowed in a field name',
            'X-Fine' => 'yes',
        ]);

        $names = $message->getEmail()->getHeaders()->getNames();
        $this->assertSame(['x-fine'], $names);
        $this->assertCount(1, $email->skipped);
        $this->assertSame('X Broken Name', $email->skipped[0]['name']);
        $this->assertStringContainsString('not a valid header name', $email->skipped[0]['reason']);
    }

    public function testAColonInTheNameIsRefused(): void
    {
        $email = $this->email();
        $email->applyHeaders(new Message(), ['X-Bad:' => 'trailing colon']);

        $this->assertCount(1, $email->skipped);
    }

    public function testSettingTheSameHeaderTwiceReplacesRatherThanRepeats(): void
    {
        $email = $this->email();
        $message = new Message();

        $email->applyHeaders($message, ['List-Unsubscribe' => '<https://example.com/first>']);
        $email->applyHeaders($message, ['List-Unsubscribe' => '<https://example.com/second>']);

        $this->assertSame(['List-Unsubscribe: <https://example.com/second>'], $this->lines($message));
    }

    public function testAListOfValuesWritesTheHeaderOncePerEntry(): void
    {
        $message = $this->email()->applyHeaders(new Message(), [
            'X-KC-Tag' => ['launch', 'september'],
        ]);

        $this->assertSame(['X-KC-Tag: launch', 'X-KC-Tag: september'], $this->lines($message));
    }

    public function testMessageIdIsWrittenAsAnIdentificationHeader(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), [
            'Message-ID' => 'kc-newsletter-12-345@example.com',
        ]);

        // Bare in the header body, in angle brackets on the wire: the brackets
        // are the grammar, not part of the id, and a caller storing the id
        // alongside the send row wants the bare form back.
        $this->assertSame(
            ['kc-newsletter-12-345@example.com'],
            $message->getEmail()->getHeaders()->get('Message-ID')->getBody()
        );
        $this->assertSame(['Message-ID: <kc-newsletter-12-345@example.com>'], $this->lines($message));
        $this->assertSame([], $email->skipped);
    }

    public function testAHeaderSymfonyRefusesIsSkippedAndLeavesTheOldValueAlone(): void
    {
        $email = $this->email();
        $message = new Message();
        $message->from('sender@example.com');

        // `From` has to be a mailbox list, so a loose string is refused. The
        // point of the assertion is the second half: the message still has the
        // From it had, rather than losing it to a half-applied replacement.
        $email->applyHeaders($message, ['From' => 'someone-else@example.com']);

        $this->assertCount(1, $email->skipped);
        $this->assertSame('From', $email->skipped[0]['name']);
        $this->assertSame('sender@example.com', $message->getEmail()->getFrom()[0]->getAddress());
    }

    public function testARepeatOfAHeaderThatMustBeUniqueIsSkipped(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), [
            'Message-ID' => ['one@example.com', 'two@example.com'],
        ]);

        $this->assertCount(1, $email->skipped);
        $this->assertSame([], $this->lines($message));
    }

    public function testAValueThatIsNotAStringIsSkippedAndLogged(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), [
            'X-Object' => new \stdClass(),
            'X-Fine' => 'yes',
        ]);

        $this->assertSame(['X-Fine: yes'], $this->lines($message));
        $this->assertCount(1, $email->skipped);
        $this->assertSame('X-Object', $email->skipped[0]['name']);
    }

    public function testANumericValueIsWrittenAsText(): void
    {
        $message = $this->email()->applyHeaders(new Message(), ['X-KC-Campaign' => 12]);

        $this->assertSame(['X-KC-Campaign: 12'], $this->lines($message));
    }

    public function testAnEmptyListLeavesTheMessageAlone(): void
    {
        $email = $this->email();
        $message = $email->applyHeaders(new Message(), ['X-Nothing' => []]);

        $this->assertSame([], $this->lines($message));
        $this->assertSame([], $email->skipped);
    }

    public function testCallersCanAskWhetherThisPluginUnderstandsTheParameter(): void
    {
        $this->assertTrue(Email::supportsParameter('headers'));
        $this->assertTrue(Email::supportsParameter('tags'));
        $this->assertTrue(Email::supportsParameter('metadata'));
        $this->assertFalse(Email::supportsParameter('something-later'));
    }
}
