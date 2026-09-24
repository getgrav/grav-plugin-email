<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers\Inbound;

use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\Receivers\CloudflareReceiver;
use Grav\Plugin\Email\Providers\Inbound\Receivers\GenericReceiver;
use Grav\Plugin\Email\Providers\Inbound\Receivers\SignedRawReceiver;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookSetup;
use PHPUnit\Framework\TestCase;

/**
 * The gateway a consumer calls: resolve, size check, verify, parse, in that
 * order, stopping at the first that fails.
 */
final class InboundGatewayTest extends TestCase
{
    private const SECRET = 'k3Jx9pQ2mV7wZ4tB8nL1cR6yH0sD5fGa';

    public function testTheFeatureIsAdvertised(): void
    {
        $this->assertTrue(Email::supportsFeature('inbound'));
        $this->assertTrue(Email::supportsFeature(Email::FEATURE_INBOUND));
        $this->assertTrue(Email::supportsFeature('providers'));
        $this->assertFalse(Email::supportsFeature('outbound-pigeons'));
    }

    public function testBuiltInsArePresentWithoutAnyProvider(): void
    {
        $receivers = (new InboundGateway())->receivers();

        $this->assertSame(['cloudflare', 'generic'], array_keys($receivers));
        $this->assertInstanceOf(CloudflareReceiver::class, $receivers['cloudflare']);
        $this->assertInstanceOf(GenericReceiver::class, $receivers['generic']);
    }

    public function testProvidersThatCanReceiveAreFoundAndOthersAreNot(): void
    {
        $spy = new SpyReceiver('spymail');
        $registry = new ProviderRegistry();
        $registry->add(new FakeInboundProvider('spymail', $spy));
        $registry->add(new FakeSendOnlyProvider('sendonly'));
        $registry->add(new FakeInboundProvider('squatter', new SpyReceiver('cloudflare')));
        $registry->add(new FakeInboundProvider('broken', null));

        $gateway = new InboundGateway(null, $registry);
        $receivers = $gateway->receivers();

        $this->assertSame(['cloudflare', 'generic', 'spymail'], array_keys($receivers));
        $this->assertSame($spy, $gateway->receiver('SpyMail'));
        $this->assertInstanceOf(CloudflareReceiver::class, $receivers['cloudflare'], 'a provider cannot take a built-in key');
    }

    public function testAnUnknownReceiverIs404(): void
    {
        $result = (new InboundGateway())->receive('nope', new InboundRequest(body: 'x'), []);

        $this->assertSame(404, $result->status);
        $this->assertNull($result->receiver);
        $this->assertFalse($result->accepted());
        $this->assertFalse($result->verdict->ok);
    }

    public function testAnOversizeBodyIsRefusedBeforeVerification(): void
    {
        $spy = new SpyReceiver('spymail', maxBytes: 100);
        $gateway = $this->gatewayWith($spy);

        $result = $gateway->receive('spymail', new InboundRequest(body: str_repeat('x', 101)), []);
        $this->assertSame(413, $result->status);
        $this->assertSame(0, $spy->verifyCalls, 'verify must not run on an oversize body');
        $this->assertSame(0, $spy->parseCalls);

        // The consumer's own limit, when smaller, wins.
        $result = $gateway->receive('spymail', new InboundRequest(body: str_repeat('x', 60)), ['max_bytes' => 50]);
        $this->assertSame(413, $result->status);
        $this->assertSame(0, $spy->verifyCalls);

        // A declared Content-Length over the limit is refused even when the body read is short.
        $result = $gateway->receive('spymail', new InboundRequest(headers: ['content-length' => '5000'], body: 'x'), []);
        $this->assertSame(413, $result->status);

        // Multipart posts count their fields and files.
        $result = $gateway->receive('spymail', new InboundRequest(parsedBody: ['body-plain' => str_repeat('y', 200)]), []);
        $this->assertSame(413, $result->status);
        $this->assertSame(0, $spy->verifyCalls);

        $result = $gateway->receive('spymail', new InboundRequest(body: str_repeat('x', 100)), []);
        $this->assertSame(200, $result->status);
        $this->assertSame(1, $spy->verifyCalls);
    }

    public function testTheBuiltInLimitAppliesToCloudflare(): void
    {
        $body = str_repeat('x', 1024);
        $result = (new InboundGateway())->receive('cloudflare', new InboundRequest(body: $body), ['secret' => self::SECRET, 'max_bytes' => 512]);

        $this->assertSame(413, $result->status);
    }

    public function testARefusedVerdictIs401AndNothingIsParsed(): void
    {
        $spy = new SpyReceiver('spymail', verdict: Verdict::refused('bad signature'));
        $result = $this->gatewayWith($spy)->receive('spymail', new InboundRequest(body: 'x'), []);

        $this->assertSame(401, $result->status);
        $this->assertSame('bad signature', $result->verdict->reason);
        $this->assertSame(0, $spy->parseCalls);
        $this->assertSame([], $result->messages());
    }

    public function testAVerifyThatThrowsIsARefusal(): void
    {
        $spy = new SpyReceiver('spymail', throwOnVerify: true);
        $result = $this->gatewayWith($spy)->receive('spymail', new InboundRequest(body: 'x'), []);

        $this->assertSame(401, $result->status);
        $this->assertSame(0, $spy->parseCalls);
    }

    public function testAConfirmationSkipsParsing(): void
    {
        $spy = new SpyReceiver('spymail', verdict: Verdict::confirm('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription'));
        $result = $this->gatewayWith($spy)->receive('spymail', new InboundRequest(body: '{}'), []);

        $this->assertSame(200, $result->status);
        $this->assertSame('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription', $result->payload->confirmUrl);
        $this->assertSame(0, $spy->parseCalls);
    }

    public function testAParseThatThrowsBecomesUnreadable(): void
    {
        $spy = new SpyReceiver('spymail', throwOnParse: true);
        $result = $this->gatewayWith($spy)->receive('spymail', new InboundRequest(body: 'x'), []);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->payload->unreadable);
        $this->assertStringContainsString('boom', $result->payload->note);
    }

    public function testASignedCloudflareRequestEndToEnd(): void
    {
        $raw = (string)file_get_contents(\dirname(__DIR__, 3) . '/fixtures/inbound/gmail-reply.eml');
        $request = new InboundRequest(
            'POST',
            '/_helpdesk/inbound/cloudflare/secret',
            [],
            [
                'content-type' => 'message/rfc822',
                'x-grav-signature' => SignedRawReceiver::sign($raw, self::SECRET),
                'x-grav-envelope-to' => 'support+t9zz1@helpdesk.example.com',
                'x-grav-envelope-from' => 'sam.customer@gmail.com',
            ],
            $raw,
        );

        $result = (new InboundGateway())->receive('cloudflare', $request, ['secret' => self::SECRET]);

        $this->assertSame(200, $result->status);
        $this->assertTrue($result->accepted());
        $this->assertSame('cloudflare', $result->receiver?->key());
        $this->assertCount(1, $result->messages());
        $this->assertSame([], $result->references());
        $message = $result->messages()[0];
        $this->assertSame(['support+t9zz1@helpdesk.example.com'], $message->envelopeTo);
        $this->assertSame('hd.1001.bb22@helpdesk.example.com', $message->inReplyTo);
        $this->assertSame('cloudflare', $message->receiver);

        $refused = (new InboundGateway())->receive('cloudflare', $request, ['secret' => str_repeat('z', 40)]);
        $this->assertSame(401, $refused->status);
    }

    public function testReferencesAreSeparatedFromMessages(): void
    {
        $spy = new SpyReceiver('spymail', payload: InboundPayload::of([
            new InboundReference('spymail', 'msg-1', ['url' => 'https://api.example/messages/msg-1']),
        ]));
        $result = $this->gatewayWith($spy)->receive('spymail', new InboundRequest(body: '{}'), []);

        $this->assertSame([], $result->messages());
        $this->assertSame('msg-1', $result->references()[0]->id);
    }

    private function gatewayWith(InboundReceiver $receiver): InboundGateway
    {
        $registry = new ProviderRegistry();
        $registry->add(new FakeInboundProvider($receiver->key(), $receiver));

        return new InboundGateway(null, $registry);
    }
}

final class SpyReceiver implements InboundReceiver
{
    public int $verifyCalls = 0;
    public int $parseCalls = 0;

    public function __construct(
        private readonly string $key,
        private readonly int $maxBytes = 1000,
        private readonly ?Verdict $verdict = null,
        private readonly ?InboundPayload $payload = null,
        private readonly bool $throwOnVerify = false,
        private readonly bool $throwOnParse = false,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Spy';
    }

    public function verificationKeys(): array
    {
        return [];
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $this->verifyCalls++;
        if ($this->throwOnVerify) {
            throw new \RuntimeException('kaput');
        }

        return $this->verdict ?? Verdict::unsigned();
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        $this->parseCalls++;
        if ($this->throwOnParse) {
            throw new \RuntimeException('boom');
        }

        return $this->payload ?? InboundPayload::nothing('spy');
    }

    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        return new InboundMessage(receiver: $this->key);
    }

    public function instructions(string $webhookUrl): string
    {
        return '';
    }
}

class FakeSendOnlyProvider implements Provider
{
    public function __construct(protected readonly string $key)
    {
    }

    public function engines(): array
    {
        return [$this->key];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, false);
    }

    public function reports(): ?DeliveryReports
    {
        return null;
    }

    public function setup(): ?WebhookSetup
    {
        return null;
    }

    public function domain(): DomainFacts
    {
        return new DomainFacts();
    }

    public function instructions(): string
    {
        return '';
    }
}

final class FakeInboundProvider extends FakeSendOnlyProvider implements InboundCapable
{
    public function __construct(string $key, private readonly ?InboundReceiver $receiver)
    {
        parent::__construct($key);
    }

    public function inbound(): InboundReceiver
    {
        return $this->receiver ?? throw new \RuntimeException('misconfigured');
    }
}
