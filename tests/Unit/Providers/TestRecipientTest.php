<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\WebhookSetup;
use PHPUnit\Framework\TestCase;

/**
 * `testRecipient()` is optional both ways round, and these hold it there.
 *
 * The method lives on a transport plugin's provider class and never on the
 * interface: a method added to `Provider` would be a fatal error in every
 * transport plugin not updated in the same release. So the first test fails
 * the day somebody "tidies" it onto the interface, and the second is a provider
 * that has it, registered and found like any other.
 */
final class TestRecipientTest extends TestCase
{
    public function testTheInterfaceDoesNotDeclareIt(): void
    {
        self::assertFalse(
            method_exists(Provider::class, 'testRecipient'),
            'testRecipient() on the interface would break every transport plugin without it'
        );
    }

    public function testAProviderThatHasItRegistersAndAnswers(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new class implements Provider {
            public function testRecipient(): ?string
            {
                return 'delivered@resend.dev';
            }

            public function engines(): array
            {
                return ['resend'];
            }

            public function key(): string
            {
                return 'resend';
            }

            public function label(): string
            {
                return 'Resend';
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
                return new DomainFacts(null, null, null);
            }

            public function instructions(): string
            {
                return '';
            }
        });

        $provider = $registry->byKey('resend');

        self::assertNotNull($provider);
        self::assertTrue(method_exists($provider, 'testRecipient'));
        self::assertSame('delivered@resend.dev', $provider->testRecipient());
    }
}
