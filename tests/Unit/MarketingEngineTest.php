<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Message;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Newsletter mail through one provider, the rest of the site's mail through
 * another, and everything through the one engine when no newsletter engine is set.
 */
final class MarketingEngineTest extends TestCase
{
    public function testNoNewsletterEngineFallsBackToTheSitesOwn(): void
    {
        self::assertSame('cloudflare', Email::marketingEngineIn(['engine' => 'cloudflare']));
        self::assertSame('cloudflare', Email::marketingEngineIn(['engine' => 'cloudflare', 'marketing_engine' => ' ']));
        self::assertSame('resend', Email::marketingEngineIn(['engine' => 'cloudflare', 'marketing_engine' => 'resend']));
    }

    public function testAMessageIsSiteMailUntilMarkedAsMarketing(): void
    {
        $message = new Message();
        self::assertFalse($message->isMarketing());
        self::assertSame($message, $message->marketing());
        self::assertTrue($message->isMarketing());
        self::assertFalse($message->marketing(false)->isMarketing());
    }

    public function testMarketingMailGoesThroughTheNewsletterEngine(): void
    {
        $email = SplitEmail::make('cloudflare', 'resend');

        self::assertSame('site', $email->pick(new Message())->name);
        self::assertSame('resend', $email->pick((new Message())->marketing())->name);
        self::assertSame('resend', $email->pick((new Message())->marketing())->name, 'built once');
        self::assertSame(['resend'], SplitEmail::$built);
    }

    public function testWithOneEngineMarketingMailUsesTheSitesTransport(): void
    {
        $email = SplitEmail::make('cloudflare', 'cloudflare');

        self::assertSame('site', $email->pick((new Message())->marketing())->name);
        self::assertSame([], SplitEmail::$built);
    }
}

final class SplitEmail extends Email
{
    /** @var list<string|null> */
    public static array $built = [];

    public static string $site = '';

    public static string $marketing = '';

    public static function make(string $site, string $marketing): self
    {
        self::$built = [];
        self::$site = $site;
        self::$marketing = $marketing;

        /** @var self $email */
        $email = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $email->transport = new NamedTransport('site');

        return $email;
    }

    public function pick(Message $message): NamedTransport
    {
        $transport = $this->transportFor($message);
        \assert($transport instanceof NamedTransport);

        return $transport;
    }

    public static function marketingEngine(): string
    {
        return self::$marketing;
    }

    protected static function siteEngine(): string
    {
        return self::$site;
    }

    protected static function getTransport(?string $engine = null): TransportInterface
    {
        self::$built[] = $engine;

        return new NamedTransport((string)$engine);
    }
}

final class NamedTransport implements TransportInterface
{
    public function __construct(public readonly string $name)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->name . '://';
    }
}
