<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * The four value objects a provider answers with.
 *
 * These moved here from the KahunaCart Newsletter add-on, where they were
 * written and where the tidying rules below were each learned from a real
 * payload. The vocabulary changed on the way — `bounced` rather than `bounce`,
 * and `dropped` is new — and the tests came with them.
 */
final class EventTest extends TestCase
{
    public function testTheSixTypesAreTheVocabulary(): void
    {
        $this->assertSame(
            ['delivered', 'bounced', 'complained', 'opened', 'clicked', 'dropped'],
            Event::TYPES
        );
        $this->assertTrue(Event::of(Event::BOUNCED)->known());
        $this->assertFalse(Event::of('deferred')->known());
    }

    public function testAMessageIdLosesItsAngleBracketsAndKeepsEverythingElse(): void
    {
        $this->assertSame(
            'kc-newsletter-12-345@example.com',
            Event::of(Event::DELIVERED, messageId: '<kc-newsletter-12-345@example.com>')->messageId
        );
        $this->assertSame(
            'kc-newsletter-12-345@example.com',
            Event::of(Event::DELIVERED, messageId: ' kc-newsletter-12-345@example.com ')->messageId
        );
        // A stray opening bracket is not the grammar and is left alone rather
        // than silently trimmed into a different id.
        $this->assertSame(
            '<half-a-bracket@example.com',
            Event::of(Event::DELIVERED, messageId: '<half-a-bracket@example.com')->messageId
        );
    }

    public function testAnAddressLosesItsDisplayNameAndIsLowerCased(): void
    {
        $this->assertSame(
            'jane@example.com',
            Event::of(Event::BOUNCED, email: 'Jane Smith <Jane@Example.com>')->email
        );
        $this->assertSame('jane@example.com', Event::of(Event::BOUNCED, email: ' JANE@example.com ')->email);
        $this->assertNull(Event::of(Event::BOUNCED, email: '   ')->email);
    }

    public function testAReasonIsCollapsedToOneLineAndCapped(): void
    {
        $event = Event::of(Event::BOUNCED, reason: "550 5.1.1\n   user   unknown\r\n");
        $this->assertSame('550 5.1.1 user unknown', $event->reason);

        $long = Event::of(Event::BOUNCED, reason: str_repeat('x', Event::MAX_REASON + 50));
        $this->assertSame(Event::MAX_REASON, mb_strlen((string)$long->reason));

        $this->assertNull(Event::of(Event::BOUNCED, reason: '   ')->reason);
    }

    public function testAHardBounceIsOnlyOneTheProviderCalledPermanent(): void
    {
        $this->assertTrue(Event::of(Event::BOUNCED, hard: true)->isHardBounce());
        $this->assertFalse(Event::of(Event::BOUNCED, hard: false)->isHardBounce());
        // Amazon's `Undetermined`. A maybe is not a permanent failure.
        $this->assertFalse(Event::of(Event::BOUNCED, hard: null)->isHardBounce());
        $this->assertFalse(Event::of(Event::DROPPED, hard: true)->isHardBounce());
    }

    public function testARefusedAddressIsOnlyADropTheProviderCalledPermanent(): void
    {
        // The provider refused the address: it is on their suppression list,
        // or it bounced or complained there before.
        $this->assertTrue(Event::of(Event::DROPPED, hard: true)->isRefusedAddress());

        // The provider refused this message: a quota, a virus, bad content.
        // The address is fine and nobody should come off a list for it.
        $this->assertFalse(Event::of(Event::DROPPED, hard: false)->isRefusedAddress());

        // A provider that would not say reads as the message, not the address.
        $this->assertFalse(Event::of(Event::DROPPED, hard: null)->isRefusedAddress());

        // A bounce is a bounce however permanent it was; the two questions are
        // asked separately because a store may answer them differently.
        $this->assertFalse(Event::of(Event::BOUNCED, hard: true)->isRefusedAddress());
        $this->assertFalse(Event::of(Event::COMPLAINED, hard: true)->isRefusedAddress());
    }

    public function testATimestampIsOnlyStampedOnAnEventThatHadNone(): void
    {
        $this->assertSame(1757030400, Event::of(Event::OPENED)->at(1757030400)->at);
        $this->assertSame(99, Event::of(Event::OPENED, at: 99)->at(1757030400)->at);
        // A negative timestamp is a parser that read a field it should not have.
        $this->assertSame(0, Event::of(Event::OPENED, at: -5)->at);
    }

    public function testASendIdIsCarriedAsTheStringTheProviderEchoed(): void
    {
        $this->assertSame('41', Event::of(Event::DELIVERED, sendId: ' 41 ')->sendId);
        $this->assertNull(Event::of(Event::DELIVERED, sendId: '  ')->sendId);
        $this->assertNull(Event::of(Event::DELIVERED)->sendId);
    }

    public function testTheWholeEventAsPlainData(): void
    {
        $event = Event::of(
            Event::COMPLAINED,
            email: 'Jane <jane@example.com>',
            messageId: '<abc@example.com>',
            providerId: ' pm-999 ',
            at: 1757030400,
            reason: 'spam report',
            sendId: '41',
        );

        $this->assertSame([
            'type' => 'complained',
            'hard' => null,
            'email' => 'jane@example.com',
            'message_id' => 'abc@example.com',
            'provider_id' => 'pm-999',
            'at' => 1757030400,
            'reason' => 'spam report',
            'send_id' => '41',
        ], $event->toArray());
    }

    public function testAPayloadOfEventsIsAListWhateverKeysItArrivedWith(): void
    {
        $payload = Payload::of([3 => Event::of(Event::DELIVERED), 7 => Event::of(Event::OPENED)]);

        $this->assertCount(2, $payload->events);
        $this->assertSame([0, 1], array_keys($payload->events));
        $this->assertFalse($payload->isEmpty());
        $this->assertFalse($payload->unreadable);
    }

    public function testNothingAndUnreadableAreDifferentAnswers(): void
    {
        $nothing = Payload::nothing('an event type this store does not act on');
        $this->assertTrue($nothing->isEmpty());
        $this->assertFalse($nothing->unreadable);
        $this->assertSame('an event type this store does not act on', $nothing->note);

        $unreadable = Payload::unreadable('the body was not JSON');
        $this->assertTrue($unreadable->isEmpty());
        $this->assertTrue($unreadable->unreadable);
        $this->assertSame('the body was not JSON', $unreadable->note);
    }

    public function testAPayloadCanNameAUrlToConfirmASubscriptionWith(): void
    {
        $payload = Payload::confirm('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription');

        $this->assertSame('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription', $payload->confirmUrl);
        $this->assertTrue($payload->isEmpty());
        $this->assertFalse($payload->unreadable);
    }

    public function testTheFourVerdicts(): void
    {
        $verified = Verdict::verified();
        $this->assertTrue($verified->ok);
        $this->assertTrue($verified->signed);
        $this->assertNull($verified->confirmUrl);

        $unsigned = Verdict::unsigned();
        $this->assertTrue($unsigned->ok);
        $this->assertFalse($unsigned->signed);

        $refused = Verdict::refused('the signature did not match the signing key on file');
        $this->assertFalse($refused->ok);
        $this->assertSame('the signature did not match the signing key on file', $refused->reason);

        $confirm = Verdict::confirm('https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription');
        $this->assertTrue($confirm->ok);
        $this->assertSame('https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription', $confirm->confirmUrl);
    }

    public function testASetupResultCarriesASentenceAndSometimesAnId(): void
    {
        $ok = SetupResult::ok('The webhook was created in SMTP2GO.', 'wh_123');
        $this->assertTrue($ok->ok);
        $this->assertSame('wh_123', $ok->webhookId);

        $failed = SetupResult::failed('The API key was refused. It needs the Manage Webhooks permission.');
        $this->assertFalse($failed->ok);
        $this->assertNull($failed->webhookId);
        $this->assertStringContainsString('Manage Webhooks', $failed->message);
    }
}
