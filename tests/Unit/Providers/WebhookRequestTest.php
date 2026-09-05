<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The request a provider is handed, from both of its constructors.
 *
 * Two things matter here and both of them are things that break a signature
 * check rather than break a screen. The headers have to be lower-cased however
 * they arrived, because every provider spells its own differently and a proxy
 * is free to change the case of any of them. And the body has to come through
 * byte for byte, because four of the six signature schemes are a hash over
 * exactly those bytes and a body that was decoded and re-encoded on the way
 * will not verify however right the key is.
 */
final class WebhookRequestTest extends TestCase
{
    public function testThePlainConstructorKeepsWhatItIsGiven(): void
    {
        $request = new WebhookRequest(
            'POST',
            '/shop/newsletter/webhook/smtp2go/abc',
            ['page' => '2'],
            ['content-type' => 'application/json'],
            '{"event":"delivered"}',
            '203.0.113.7',
        );

        $this->assertSame('POST', $request->method);
        $this->assertSame('/shop/newsletter/webhook/smtp2go/abc', $request->path);
        $this->assertSame(['page' => '2'], $request->query);
        $this->assertSame('{"event":"delivered"}', $request->body);
        $this->assertSame('203.0.113.7', $request->remoteAddress);
    }

    public function testTheDefaultsAreAnEmptyPostWithNothingInIt(): void
    {
        $request = new WebhookRequest();

        $this->assertSame('POST', $request->method);
        $this->assertSame('', $request->body);
        $this->assertSame([], $request->headers);
        $this->assertSame('', $request->header('anything'));
        $this->assertFalse($request->hasHeader('anything'));
    }

    public function testAHeaderIsReadCaseInsensitively(): void
    {
        $request = new WebhookRequest(headers: ['svix-id' => 'msg_123']);

        $this->assertSame('msg_123', $request->header('Svix-Id'));
        $this->assertSame('msg_123', $request->header('  SVIX-ID '));
        $this->assertTrue($request->hasHeader('SVIX-ID'));
    }

    public function testAHeaderThatArrivedEmptyIsNotTheSameAsOneThatDidNotArrive(): void
    {
        $request = new WebhookRequest(headers: ['x-mailgun-signature' => '']);

        $this->assertTrue($request->hasHeader('x-mailgun-signature'));
        $this->assertFalse($request->hasHeader('x-twilio-email-event-webhook-signature'));
    }

    public function testFromServerRequestLowerCasesEveryHeaderName(): void
    {
        $psr = (new ServerRequest('POST', 'https://example.com/newsletter/webhook/sendgrid/s3cret'))
            ->withHeader('X-Twilio-Email-Event-Webhook-Signature', 'sig')
            ->withHeader('X-Twilio-Email-Event-Webhook-Timestamp', '1757030400')
            ->withHeader('Content-Type', 'application/json');

        $request = WebhookRequest::fromServerRequest($psr);

        $this->assertArrayHasKey('x-twilio-email-event-webhook-signature', $request->headers);
        $this->assertArrayHasKey('x-twilio-email-event-webhook-timestamp', $request->headers);
        $this->assertSame('sig', $request->header('X-Twilio-Email-Event-Webhook-Signature'));
        $this->assertSame('application/json', $request->header('content-type'));
    }

    public function testFromServerRequestTakesTheRawBodyUntouchedAndLeavesItReadable(): void
    {
        // Deliberately not tidy JSON: the whitespace and the escape are exactly
        // what a signature is computed over, and re-encoding would lose both.
        $body = "{ \"events\" : [ {\"type\":\"bounced\",\"reason\":\"550 5.1.1 user unknown\"} ] }\n";

        $psr = (new ServerRequest('POST', 'https://example.com/hook'))
            ->withBody(\Nyholm\Psr7\Stream::create($body));

        $request = WebhookRequest::fromServerRequest($psr);

        $this->assertSame($body, $request->body);
        // Rewound, so anything else that reads the stream still sees the body.
        $this->assertSame($body, (string)$psr->getBody());
    }

    public function testFromServerRequestReadsTheBodyEvenAfterSomebodyElseHas(): void
    {
        $psr = (new ServerRequest('POST', 'https://example.com/hook'))
            ->withBody(\Nyholm\Psr7\Stream::create('{"ok":true}'));

        // Somebody read it first, which is what a logging middleware does.
        (string)$psr->getBody();

        $this->assertSame('{"ok":true}', WebhookRequest::fromServerRequest($psr)->body);
    }

    public function testFromServerRequestTakesThePathQueryMethodAndAddress(): void
    {
        $psr = new ServerRequest(
            'post',
            'https://example.com/shop/newsletter/webhook/mailgun/abc?retry=3',
            [],
            null,
            '1.1',
            ['REMOTE_ADDR' => '198.51.100.4'],
        );

        $request = WebhookRequest::fromServerRequest($psr->withQueryParams(['retry' => '3']));

        $this->assertSame('POST', $request->method);
        $this->assertSame('/shop/newsletter/webhook/mailgun/abc', $request->path);
        $this->assertSame(['retry' => '3'], $request->query);
        $this->assertSame('198.51.100.4', $request->remoteAddress);
    }

    public function testARepeatedHeaderComesThroughAsOneValue(): void
    {
        $psr = (new ServerRequest('POST', 'https://example.com/hook'))
            ->withHeader('X-Repeat', ['one', 'two']);

        $this->assertSame('one, two', WebhookRequest::fromServerRequest($psr)->header('x-repeat'));
    }

    public function testJsonReadsAnObjectAndAList(): void
    {
        $this->assertSame(
            ['event' => 'delivered'],
            (new WebhookRequest(body: '{"event":"delivered"}'))->json()
        );

        // SendGrid posts a list where everybody else posts an object.
        $this->assertSame(
            [['event' => 'bounce'], ['event' => 'open']],
            (new WebhookRequest(body: '[{"event":"bounce"},{"event":"open"}]'))->json()
        );
    }

    public function testJsonAnswersNullForAnythingThatIsNotJson(): void
    {
        $this->assertNull((new WebhookRequest(body: ''))->json());
        $this->assertNull((new WebhookRequest(body: '   '))->json());
        $this->assertNull((new WebhookRequest(body: '<html>502 Bad Gateway</html>'))->json());
        $this->assertNull((new WebhookRequest(body: '{"truncated": '))->json());
        // A bare scalar is valid JSON and is not a payload.
        $this->assertNull((new WebhookRequest(body: '"delivered"'))->json());
    }
}
