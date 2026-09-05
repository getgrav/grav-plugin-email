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
 * The registry, which is the whole of what `onEmailProviders` hands round.
 *
 * The interesting half is the refusals. A site where two plugins both answer
 * for one engine has one of them quietly winning on directory order, and a
 * merchant would see delivery events verified with the wrong key and nothing
 * anywhere saying why — so both clashes fail loudly, and the messages are
 * asserted here because a merchant reading one is the whole point of throwing.
 */
final class ProviderRegistryTest extends TestCase
{
    /** @param list<string> $engines */
    private function provider(string $key, array $engines): Provider
    {
        return new class ($key, $engines) implements Provider {
            /** @param list<string> $engines */
            public function __construct(private string $providerKey, private array $providerEngines)
            {
            }

            public function engines(): array
            {
                return $this->providerEngines;
            }

            public function key(): string
            {
                return $this->providerKey;
            }

            public function label(): string
            {
                return ucfirst($this->providerKey);
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
        };
    }

    public function testAProviderIsFoundByItsEngineAndByItsKey(): void
    {
        $registry = new ProviderRegistry();
        $smtp2go = $this->provider('smtp2go', ['smtp2go']);
        $registry->add($smtp2go);

        $this->assertSame($smtp2go, $registry->forEngine('smtp2go'));
        $this->assertSame($smtp2go, $registry->byKey('smtp2go'));
        $this->assertSame(['smtp2go' => $smtp2go], $registry->all());
        $this->assertFalse($registry->isEmpty());
    }

    public function testAnEngineWithNoProviderAnswersNull(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('smtp2go', ['smtp2go']));

        $this->assertNull($registry->forEngine('postmark'));
        $this->assertNull($registry->byKey('postmark'));
    }

    public function testAnEmptyRegistrySaysSo(): void
    {
        $registry = new ProviderRegistry();

        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], $registry->all());
        $this->assertSame([], $registry->engines());
        $this->assertSame([], $registry->keys());
    }

    public function testOneProviderMayAnswerForSeveralEngines(): void
    {
        $registry = new ProviderRegistry();
        $ses = $this->provider('ses', ['ses', 'amazon-ses', 'aws-ses']);
        $registry->add($ses);

        $this->assertSame($ses, $registry->forEngine('ses'));
        $this->assertSame($ses, $registry->forEngine('amazon-ses'));
        $this->assertSame($ses, $registry->forEngine('aws-ses'));
        $this->assertSame(['ses', 'amazon-ses', 'aws-ses'], $registry->engines());
        $this->assertSame(['ses'], $registry->keys());
    }

    public function testEngineAndKeyLookupsAreCaseAndSpaceInsensitive(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('SMTP2GO', [' SMTP2GO ']));

        $this->assertNotNull($registry->forEngine('smtp2go'));
        $this->assertNotNull($registry->forEngine('  SMTP2GO'));
        $this->assertNotNull($registry->byKey('Smtp2Go'));
    }

    public function testTwoProvidersClaimingOneEngineIsRefusedByName(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('smtp2go', ['smtp2go']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/both answer for the "smtp2go" engine/');

        $registry->add($this->provider('other', ['smtp2go']));
    }

    public function testTheEngineClashMessageSaysWhatToDoAboutIt(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('ses', ['ses']));

        try {
            $registry->add($this->provider('amazon', ['ses']));
            $this->fail('a second provider claiming the ses engine should have been refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Disable one of the two plugins', $e->getMessage());
        }
    }

    public function testTwoProvidersSharingOneKeyIsRefused(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('postmark', ['postmark']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/both call themselves "postmark"/');

        $registry->add($this->provider('postmark', ['postmarkapp']));
    }

    public function testAnEmptyKeyIsRefused(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty key\(\)/');

        $registry->add($this->provider('   ', ['whatever']));
    }

    public function testAddingTheSameProviderTwiceIsNotAClash(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->provider('mailgun', ['mailgun']);

        $registry->add($provider);
        $registry->add($provider);

        $this->assertSame(['mailgun'], $registry->keys());
        $this->assertSame(['mailgun'], $registry->engines());
    }

    public function testAProviderThatClashedLeavesTheRegistryAsItWas(): void
    {
        $registry = new ProviderRegistry();
        $first = $this->provider('sendgrid', ['sendgrid']);
        $registry->add($first);

        try {
            $registry->add($this->provider('twilio', ['twilio-sendgrid', 'sendgrid']));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(['sendgrid' => $first], $registry->all());
        $this->assertNull($registry->forEngine('twilio-sendgrid'));
    }

    public function testAnEmptyEngineNameIsIgnoredRatherThanClaimed(): void
    {
        $registry = new ProviderRegistry();
        $registry->add($this->provider('mailersend', ['mailersend', '', '  ']));

        $this->assertSame(['mailersend'], $registry->engines());
        $this->assertNull($registry->forEngine(''));
    }
}
