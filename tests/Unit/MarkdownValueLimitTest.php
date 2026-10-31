<?php

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use PHPUnit\Framework\TestCase;

/**
 * Form values that go into a body converted with `process_markdown` are cut to
 * a fixed length first, so a very long value cannot keep the converter busy.
 */
final class MarkdownValueLimitTest extends TestCase
{
    private const MAX = Email::MARKDOWN_VALUE_MAX_BYTES;

    public function testShortValuesAreLeftAlone(): void
    {
        $data = ['name' => 'Anna', 'tags' => ['a', 'b'], 'count' => 3, 'ok' => true, 'none' => null];

        $this->assertSame($data, Email::limitValues($data, self::MAX));
    }

    public function testLongValuesAreCutAtAnyDepth(): void
    {
        $data = ['message' => str_repeat('a', self::MAX + 500), 'nested' => ['deep' => ['text' => str_repeat('b', self::MAX * 2)]]];

        $limited = Email::limitValues($data, self::MAX);

        $this->assertSame(self::MAX, strlen($limited['message']));
        $this->assertSame(self::MAX, strlen($limited['nested']['deep']['text']));
    }

    public function testAValueAtTheLimitIsKept(): void
    {
        $value = str_repeat('a', self::MAX);

        $this->assertSame($value, Email::limitValues($value, self::MAX));
    }

    public function testACharacterIsNeverSplit(): void
    {
        // Each "é" is two bytes, so a cut at an odd length would land inside one.
        $limited = Email::limitValues(str_repeat('é', 10), 5);

        $this->assertSame('éé', $limited);
        $this->assertTrue(mb_check_encoding($limited, 'UTF-8'));
    }

    public function testTheFormHandedToTemplatesIsACopy(): void
    {
        $form = new class(['message' => str_repeat('a', self::MAX + 1), 'name' => 'Anna']) {
            public array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function value()
            {
                return $this->data;
            }

            public function setAllData($data): void
            {
                $this->data = $data;
            }
        };

        $vars = $this->email()->limit(['form' => $form, 'page' => 'p']);

        $this->assertNotSame($form, $vars['form']);
        $this->assertSame(self::MAX, strlen($vars['form']->value()['message']));
        $this->assertSame('Anna', $vars['form']->value()['name']);
        $this->assertSame('p', $vars['page']);
        // The submitted form keeps what was posted, for the other form actions.
        $this->assertSame(self::MAX + 1, strlen($form->value()['message']));
    }

    public function testAFormWithShortValuesIsNotCopied(): void
    {
        $form = new class {
            public function value()
            {
                return ['message' => 'hello'];
            }

            public function setAllData($data): void
            {
                throw new \LogicException('not expected');
            }
        };

        $this->assertSame($form, $this->email()->limit(['form' => $form])['form']);
    }

    public function testVarsWithoutAFormPassThrough(): void
    {
        $this->assertSame(['page' => 'p'], $this->email()->limit(['page' => 'p']));
    }

    private function email(): object
    {
        return new class extends Email {
            public function __construct()
            {
            }

            public function limit(array $vars): array
            {
                return $this->limitFormValues($vars);
            }
        };
    }
}
