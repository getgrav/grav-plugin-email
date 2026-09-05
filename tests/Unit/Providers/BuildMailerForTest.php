<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mailer for a named engine, and the arithmetic underneath it.
 *
 * Two halves, tested separately because only one of them can be reached without
 * a booted Grav.
 *
 * {@see Email::buildMailerFor()} is the thin half: it asks `getTransport()` for
 * an engine and wraps what comes back. The subclass below records what it was
 * asked, which is the whole of what there is to prove — that the engine passed
 * in is the engine used, and that nothing reads the configured one on the way.
 *
 * {@see Email::dsnForEngine()} is the half that decides anything, and it was
 * lifted out of `getTransport()` line for line so it could be read and tested
 * here. Every assertion in it is a DSN this plugin was already building before
 * this release; the point of writing them down is that the engine parameter did
 * not change any of them.
 */
final class BuildMailerForTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingEmail::$asked = [];
    }

    public function testAMailerIsBuiltForTheEngineItWasAskedFor(): void
    {
        $mailer = RecordingEmail::buildMailerFor('smtp2go');

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertSame(['smtp2go'], RecordingEmail::$asked);
    }

    public function testTheConfiguredEngineIsStillTheOneWithNoEngineNamed(): void
    {
        // `null` is how `initMailer()` asks, and it means "whatever this site is
        // configured with". Nothing about that path changed.
        RecordingEmail::transportForTest(null);

        $this->assertSame([null], RecordingEmail::$asked);
    }

    public function testEachCallGetsItsOwnMailerRatherThanTheConfiguredOne(): void
    {
        $first = RecordingEmail::buildMailerFor('ses');
        $second = RecordingEmail::buildMailerFor('postmark');

        $this->assertNotSame($first, $second);
        $this->assertSame(['ses', 'postmark'], RecordingEmail::$asked);
    }

    public function testTheSmtpDsnIsBuiltFromTheSmtpBlock(): void
    {
        $dsn = RecordingEmail::dsnForTest('smtps', [
            'engine' => 'smtps',
            'smtp' => [
                'server' => 'mail.example.com',
                'port' => 587,
                'user' => 'someone@example.com',
                'password' => 'p@ss word',
                'encryption' => 'tls',
            ],
        ]);

        $this->assertSame('smtps://someone%40example.com:p%40ss+word@mail.example.com:587', $dsn);
    }

    public function testEncryptionOfNoneTurnsPeerVerificationOff(): void
    {
        $dsn = RecordingEmail::dsnForTest('smtp', [
            'smtp' => ['server' => 'localhost', 'port' => 25, 'encryption' => 'none'],
        ]);

        $this->assertSame('smtp://localhost:25?verify_peer=0', $dsn);
    }

    public function testMailAndNativeBothMeanNative(): void
    {
        $this->assertSame('native://default', RecordingEmail::dsnForTest('mail', []));
        $this->assertSame('native://default', RecordingEmail::dsnForTest('native', []));
    }

    public function testSendmailCarriesTheBinaryWhenOneIsConfigured(): void
    {
        $this->assertSame('sendmail://default', RecordingEmail::dsnForTest('sendmail', []));
        $this->assertSame(
            'sendmail://default?command=%2Fusr%2Fsbin%2Fsendmail+-bs',
            RecordingEmail::dsnForTest('sendmail', ['sendmail' => ['bin' => '/usr/sbin/sendmail -bs']])
        );
    }

    public function testAnEngineThisPluginDoesNotShipIsLeftToTheTransportPlugins(): void
    {
        // Null is what sends `getTransport()` to `onEmailTransportDsn`, which is
        // how every transport plugin has always registered its own DSN.
        $this->assertNull(RecordingEmail::dsnForTest('smtp2go', []));
        $this->assertNull(RecordingEmail::dsnForTest('ses', []));
        $this->assertNull(RecordingEmail::dsnForTest('none', []));
        $this->assertNull(RecordingEmail::dsnForTest('', []));
    }

    public function testTheProvidersFeatureIsAdvertisedAndNothingElseIs(): void
    {
        $this->assertTrue(Email::supportsFeature('providers'));
        $this->assertFalse(Email::supportsFeature('something-later'));
        // The two questions are separate and neither answers for the other.
        $this->assertFalse(Email::supportsFeature('headers'));
        $this->assertFalse(Email::supportsParameter('providers'));
    }
}

/**
 * An `Email` whose transport is a stub, so a mailer can be built with no Grav.
 *
 * `getTransport()` is the one thing `buildMailerFor()` does, so replacing it is
 * replacing the network, the config and the container in one line. The two
 * `…ForTest` methods exist because the originals are protected and this suite
 * deliberately does not use reflection to reach into the class it is testing.
 */
final class RecordingEmail extends Email
{
    /** @var list<string|null> */
    public static array $asked = [];

    public function __construct()
    {
    }

    /** @param array<string, mixed> $mailer */
    public static function dsnForTest(string $engine, array $mailer): ?string
    {
        return static::dsnForEngine($engine, $mailer);
    }

    public static function transportForTest(?string $engine): TransportInterface
    {
        return static::getTransport($engine);
    }

    protected static function getTransport(?string $engine = null): TransportInterface
    {
        self::$asked[] = $engine;

        // Written out rather than Symfony's own NullTransport, which extends
        // AbstractTransport and wants a PSR logger — a package this plugin
        // deliberately `replace`s, because Grav supplies it at runtime.
        return new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return null;
            }

            public function __toString(): string
            {
                return 'test://nowhere';
            }
        };
    }
}
