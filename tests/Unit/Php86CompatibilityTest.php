<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use Grav\Plugin\Email\Message;
use Grav\Plugin\EmailPlugin;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/grav-stand-ins.php';

/**
 * Regression tests for PHP 8.6 compatibility fixes in grav-plugin-email (while keeping PHP 7.3+ support).
 */
final class Php86CompatibilityTest extends TestCase
{
    /**
     * Run a callback and fail if any E_DEPRECATED / E_USER_DEPRECATED is triggered.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function assertNoDeprecation(callable $callback): mixed
    {
        $deprecations = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $deprecations[] = $errstr;
                return true;
            }
            return false;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations, 'Unexpected deprecation(s): ' . implode('; ', $deprecations));

        return $result;
    }

    public function testFormAttachmentWithNulBytePathIsSkippedAndLoggedInsteadOfThrowing(): void
    {
        $grav = new Php86GravContainer();
        $email = new Php86SendingEmail();

        $plugin = (new \ReflectionClass(EmailPlugin::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(EmailPlugin::class, 'grav'))->setValue($plugin, $grav);
        (new \ReflectionProperty(EmailPlugin::class, 'email'))->setValue($plugin, $email);

        $form = new class {
            public function value($name)
            {
                return $name === 'upload'
                    ? [['file' => "user/data/files/evil\0.php"], ['path' => __FILE__]]
                    : null;
            }
        };

        $send = new \ReflectionMethod(EmailPlugin::class, 'sendFormEmail');
        $this->assertNoDeprecation(static function () use ($send, $plugin, $form): void {
            $send->invoke($plugin, $form, ['attachments' => ['upload']], [], new \RocketTheme\Toolbox\Event\Event());
        });

        // The NUL path never reached the locator or the message; the good one did.
        $this->assertSame([__FILE__], $grav->located);
        $this->assertSame([__FILE__], $email->message->attached);
        $this->assertCount(1, $grav->errors);
        $this->assertStringContainsString('NUL byte', $grav->errors[0]);

        // And the email still went out.
        $this->assertSame(1, $email->sent);
        $this->assertSame(['onEmailMessage', 'onEmailSent'], $grav->events);
    }
}

/** Records what `sendFormEmail()` attached instead of reading the files. */
final class Php86RecordingMessage extends Message
{
    /** @var string[] */
    public $attached = [];

    public function attachFromPath($path): Message
    {
        $this->attached[] = $path;

        return $this;
    }
}

/** An `Email` that builds an empty message and pretends to send it, with no transport or log. */
final class Php86SendingEmail extends Email
{
    /** @var Php86RecordingMessage|null */
    public $message;

    /** @var int */
    public $sent = 0;

    public function __construct()
    {
    }

    public function buildMessage(array $params, array $vars = [], bool $templatedAddresses = false): Message
    {
        return $this->message = new Php86RecordingMessage();
    }

    public function send(Message $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): int
    {
        $this->sent++;

        return 1;
    }
}

/** The slice of the Grav container `sendFormEmail()` reaches: the locator, the log and fireEvent(). */
final class Php86GravContainer implements \ArrayAccess
{
    /** @var string[] */
    public $located = [];

    /** @var string[] */
    public $errors = [];

    /** @var string[] */
    public $events = [];

    /** @var array<string, object> */
    private $services;

    public function __construct()
    {
        $this->services = [
            'locator' => new class ($this) {
                private $grav;

                public function __construct(Php86GravContainer $grav)
                {
                    $this->grav = $grav;
                }

                public function findResource($uri, $absolute = true, $first = false)
                {
                    $this->grav->located[] = $uri;

                    return $uri;
                }
            },
            'log' => new class ($this) {
                private $grav;

                public function __construct(Php86GravContainer $grav)
                {
                    $this->grav = $grav;
                }

                public function error($message): void
                {
                    $this->grav->errors[] = $message;
                }
            },
        ];
    }

    public function fireEvent($name, $event = null)
    {
        $this->events[] = $name;

        return $event;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->services[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        return $this->services[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->services[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->services[$offset]);
    }
}
