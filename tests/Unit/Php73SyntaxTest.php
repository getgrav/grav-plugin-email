<?php

namespace Grav\Plugin\Email\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The plugin still installs on the PHP 7.3 that Grav 1.7 allows. Only the
 * provider and inbound classes may use newer syntax, because
 * `Email::supportsFeature()` keeps PHP 7.3 from ever loading them. Everything
 * else loads on ordinary requests, and one PHP 7.4 arrow function in
 * `Email.php` took every 7.3 site down (getgrav/grav-plugin-email#197).
 *
 * Tokenizing on a newer PHP still names the syntax 7.3 can't parse.
 */
final class Php73SyntaxTest extends TestCase
{
    /** Tokens for syntax PHP 7.3 doesn't have, with the version that added it. */
    private const NEWER = [
        'T_FN' => 'arrow function (PHP 7.4)',
        'T_COALESCE_EQUAL' => '??= (PHP 7.4)',
        'T_MATCH' => 'match (PHP 8.0)',
        'T_NULLSAFE_OBJECT_OPERATOR' => '?-> (PHP 8.0)',
        'T_ATTRIBUTE' => 'attribute (PHP 8.0)',
        'T_READONLY' => 'readonly (PHP 8.1)',
        'T_ENUM' => 'enum (PHP 8.1)',
    ];

    /** @return string[] */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            [$root . '/email.php'],
            glob($root . '/classes/*.php') ?: [],
            glob($root . '/cli/*.php') ?: []
        );
        sort($files);

        return $files;
    }

    public function testFilesLoadedOnPhp73UseNoNewerSyntax(): void
    {
        $found = [];
        foreach ($this->files() as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                $name = token_name($token[0]);
                if (isset(self::NEWER[$name])) {
                    $found[] = sprintf('%s:%d %s', basename($file), $token[2], self::NEWER[$name]);
                }
            }
        }

        self::assertGreaterThan(5, count($this->files()));
        self::assertSame([], $found, "PHP 7.3 can't parse these, and these files load on it:\n" . implode("\n", $found));
    }

    public function testTypedPropertiesAreNotUsed(): void
    {
        // A typed property (PHP 7.4) has no token of its own: a type right
        // before a property's variable, after its visibility.
        $found = [];
        foreach ($this->files() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/^\s*(?:public|protected|private)(?:\s+static)?\s+(?!static\b|function\b|const\b)\??[A-Za-z_\\\\][\w\\\\|]*\s+\$\w+/m', $source, $m)) {
                foreach ($m[0] as $line) {
                    $found[] = basename($file) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $found, "Typed properties need PHP 7.4:\n" . implode("\n", $found));
    }
}
