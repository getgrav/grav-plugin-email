<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers\Inbound;

use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\Receivers\CloudflareReceiver;
use Grav\Plugin\Email\Providers\Inbound\Receivers\GenericReceiver;
use Grav\Plugin\Email\Providers\Inbound\Receivers\SignedRawReceiver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in `cloudflare` and `generic` receivers: an HMAC over the raw body
 * with a timestamp, checked before anything reads the body.
 *
 * Every signature here is computed in the test and then broken — a changed
 * byte, a replaced signature, an old timestamp, a short secret — because each
 * of those is a real attack and each is three lines to check.
 */
final class SignedRawReceiverTest extends TestCase
{
    private const SECRET = 'k3Jx9pQ2mV7wZ4tB8nL1cR6yH0sD5fGa';
    private const NOW = 1758620000;

    /** @return iterable<string, array{SignedRawReceiver}> */
    public static function receivers(): iterable
    {
        $clock = static fn (): int => self::NOW;
        yield 'cloudflare' => [new CloudflareReceiver($clock)];
        yield 'generic' => [new GenericReceiver($clock)];
    }

    #[DataProvider('receivers')]
    public function testAFreshSignatureVerifies(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $verdict = $receiver->verify($this->request($body, SignedRawReceiver::sign($body, self::SECRET, self::NOW)), ['secret' => self::SECRET]);

        $this->assertTrue($verdict->ok, $verdict->reason);
        $this->assertTrue($verdict->signed);
    }

    #[DataProvider('receivers')]
    public function testTheToleranceIsThreeHundredSecondsEitherWay(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        foreach ([-300, 300] as $skew) {
            $request = $this->request($body, SignedRawReceiver::sign($body, self::SECRET, self::NOW + $skew));
            $this->assertTrue($receiver->verify($request, ['secret' => self::SECRET])->ok, "skew $skew");
        }
        foreach ([-301, 301, -86400] as $skew) {
            $request = $this->request($body, SignedRawReceiver::sign($body, self::SECRET, self::NOW + $skew));
            $verdict = $receiver->verify($request, ['secret' => self::SECRET]);
            $this->assertFalse($verdict->ok, "skew $skew");
            $this->assertStringContainsString('seconds', $verdict->reason);
        }

        // A consumer may widen it.
        $request = $this->request($body, SignedRawReceiver::sign($body, self::SECRET, self::NOW - 600));
        $this->assertTrue($receiver->verify($request, ['secret' => self::SECRET, 'tolerance' => 900])->ok);
    }

    #[DataProvider('receivers')]
    public function testATamperedBodyIsRefused(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $header = SignedRawReceiver::sign($body, self::SECRET, self::NOW);
        $tampered = str_replace('jammed', 'fixed!', $body);

        $verdict = $receiver->verify($this->request($tampered, $header), ['secret' => self::SECRET]);
        $this->assertFalse($verdict->ok);
        $this->assertStringContainsString('does not match', $verdict->reason);

        // Re-encoding line endings is tampering too: the signature is over bytes.
        $verdict = $receiver->verify($this->request(str_replace("\r\n", "\n", $body), $header), ['secret' => self::SECRET]);
        $this->assertFalse($verdict->ok);
    }

    #[DataProvider('receivers')]
    public function testABadSignatureIsRefused(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $config = ['secret' => self::SECRET];

        $cases = [
            'wrong secret' => SignedRawReceiver::sign($body, str_repeat('x', 32), self::NOW),
            'replaced hex' => 't=' . self::NOW . ',v1=' . str_repeat('0', 64),
            'timestamp moved' => str_replace('t=' . self::NOW, 't=' . (self::NOW + 1), SignedRawReceiver::sign($body, self::SECRET, self::NOW)),
            'no v1' => 't=' . self::NOW,
            'no t' => 'v1=' . hash_hmac('sha256', self::NOW . '.' . $body, self::SECRET),
            'garbage' => 'hello',
        ];
        foreach ($cases as $label => $header) {
            $this->assertFalse($receiver->verify($this->request($body, $header), $config)->ok, $label);
        }

        $this->assertFalse($receiver->verify($this->request($body, null), $config)->ok, 'no header');
    }

    #[DataProvider('receivers')]
    public function testASecretShorterThanThirtyTwoCharactersIsRefusedAsUnconfigured(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $short = 'short-secret';
        $verdict = $receiver->verify($this->request($body, SignedRawReceiver::sign($body, $short, self::NOW)), ['secret' => $short]);

        $this->assertFalse($verdict->ok);
        $this->assertStringContainsString('32', $verdict->reason);
        $this->assertFalse($receiver->verify($this->request($body, SignedRawReceiver::sign($body, '', self::NOW)), [])->ok);
    }

    #[DataProvider('receivers')]
    public function testSeveralSignaturesAllowARotatingSecret(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $old = 'o1d-secret-o1d-secret-o1d-secret-o1d';
        $header = 't=' . self::NOW
            . ',v1=' . hash_hmac('sha256', self::NOW . '.' . $body, $old)
            . ',v1=' . hash_hmac('sha256', self::NOW . '.' . $body, self::SECRET);

        $this->assertTrue($receiver->verify($this->request($body, $header), ['secret' => self::SECRET])->ok);
        $this->assertTrue($receiver->verify($this->request($body, $header), ['secret' => $old])->ok);
    }

    #[DataProvider('receivers')]
    public function testParseReadsTheMessageAndTheEnvelope(SignedRawReceiver $receiver): void
    {
        $body = $this->raw();
        $request = new InboundRequest(headers: [
            'content-type' => 'message/rfc822',
            'x-grav-envelope-to' => 'support+t8f2kq@helpdesk.example.com',
            'x-grav-envelope-from' => 'bounces+123@example.net',
        ], body: $body);

        $payload = $receiver->parse($request, ['secret' => self::SECRET]);
        $this->assertFalse($payload->unreadable);
        $this->assertCount(1, $payload->items);
        $message = $payload->items[0];
        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertSame($receiver->key(), $message->receiver);
        $this->assertSame(['support+t8f2kq@helpdesk.example.com'], $message->envelopeTo);
        $this->assertSame('bounces+123@example.net', $message->envelopeFrom);
        $this->assertSame('Printer on the second floor is jammed', $message->subject);
        $this->assertSame($body, $message->raw);
    }

    #[DataProvider('receivers')]
    public function testAnEmptyEnvelopeFromIsTheNullSender(SignedRawReceiver $receiver): void
    {
        $request = new InboundRequest(headers: ['x-grav-envelope-from' => ''], body: $this->raw());
        $message = $receiver->parse($request, [])->items[0];

        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertSame('', $message->envelopeFrom);

        // Without the header, the MIME's own Return-Path stands.
        $message = $receiver->parse(new InboundRequest(body: $this->raw()), [])->items[0];
        $this->assertSame('anna@example.net', $message->envelopeFrom);
    }

    #[DataProvider('receivers')]
    public function testParseNeverThrowsAndSaysWhatWasWrong(SignedRawReceiver $receiver): void
    {
        foreach (['', "   \r\n", '{"json": "is not a message"}', "<html>502 Bad Gateway</html>"] as $body) {
            $payload = $receiver->parse(new InboundRequest(body: $body), []);
            $this->assertTrue($payload->unreadable, var_export($body, true));
            $this->assertNotSame('', $payload->note);
            $this->assertSame([], $payload->items);
        }
    }

    public function testFetchIsNeverNeeded(): void
    {
        $this->expectException(\LogicException::class);
        (new GenericReceiver())->fetch(new InboundReference('generic', 'x'), []);
    }

    public function testTheirDescriptions(): void
    {
        $cloudflare = new CloudflareReceiver();
        $generic = new GenericReceiver();

        $this->assertSame('cloudflare', $cloudflare->key());
        $this->assertSame('generic', $generic->key());
        $this->assertSame(['secret'], $cloudflare->verificationKeys());
        $this->assertSame(25 * 1024 * 1024, $cloudflare->maxBytes());
        $this->assertStringContainsString('https://example.com/_helpdesk/inbound/cloudflare/abc', $cloudflare->instructions('https://example.com/_helpdesk/inbound/cloudflare/abc'));
        $this->assertStringContainsString('X-Grav-Signature', $generic->instructions('https://example.com/x'));
    }

    private function raw(): string
    {
        return (string)file_get_contents(\dirname(__DIR__, 3) . '/fixtures/inbound/plain.eml');
    }

    private function request(string $body, ?string $signature): InboundRequest
    {
        $headers = ['content-type' => 'message/rfc822'];
        if ($signature !== null) {
            $headers['x-grav-signature'] = $signature;
        }

        return new InboundRequest('POST', '/_helpdesk/inbound/cloudflare/s', [], $headers, $body);
    }
}
