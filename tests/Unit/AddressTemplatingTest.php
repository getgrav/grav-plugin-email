<?php

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use PHPUnit\Framework\TestCase;

/**
 * Address parameters a caller hands to `buildMessage()` are used as they are; only the
 * form `email` action renders them as Twig, because page authors write
 * `to: "{{ config.plugins.email.to }}"` and the like there. Addresses that fall back to
 * the plugin configuration are rendered as before.
 */
final class AddressTemplatingTest extends TestCase
{
    private function email(): Email
    {
        return new class extends Email {
            public function __construct()
            {
            }

            public function render(array $params, array $literal): array
            {
                return $this->renderParamStrings($params, static fn (string $value): string => '[' . $value . ']', $literal);
            }
        };
    }

    public function testCallerSuppliedAddressesAreLiteral(): void
    {
        $keys = Email::literalAddressKeys([
            'to' => 'member@example.com',
            'reply_to' => ['email' => 'visitor@example.com', 'name' => 'Visitor'],
            'to_name' => 'Member',
            'subject' => 'Hello',
            'body' => 'Body',
        ], false);

        self::assertSame(['to', 'to_name', 'reply_to'], $keys);
    }

    public function testFormActionAddressesAreTemplated(): void
    {
        self::assertSame([], Email::literalAddressKeys(['to' => '{{ config.plugins.email.to }}'], true));
    }

    public function testLiteralParametersAreLeftAsTheyAre(): void
    {
        $params = $this->email()->render([
            'to' => '{{ grav.config }}@example.com',
            'reply_to' => ['email' => '{{ 7*7 }}@example.com'],
            'from' => '{{ config.plugins.email.from }}',
            'subject' => 'Hi {{ form.value.name }}',
            'body' => ['{{ x }}', ['{{ y }}']],
        ], ['to', 'reply_to']);

        self::assertSame('{{ grav.config }}@example.com', $params['to']);
        self::assertSame(['email' => '{{ 7*7 }}@example.com'], $params['reply_to']);
        // Not supplied by the caller: a configuration default, still rendered.
        self::assertSame('[{{ config.plugins.email.from }}]', $params['from']);
        self::assertSame('[Hi {{ form.value.name }}]', $params['subject']);
        self::assertSame(['[{{ x }}]', ['[{{ y }}]']], $params['body']);
    }

    public function testObjectsArePassedThrough(): void
    {
        $object = new \stdClass();
        $params = $this->email()->render(['message' => $object, 'to' => 'a@example.com'], []);

        self::assertSame($object, $params['message']);
        self::assertSame('[a@example.com]', $params['to']);
    }
}
