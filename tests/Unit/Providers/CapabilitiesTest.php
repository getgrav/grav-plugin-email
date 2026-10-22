<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers;

use Grav\Plugin\Email\Providers\Capabilities;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    /** Every transport plugin written before the flag existed means "not transactional only". */
    public function testTransactionalOnlyDefaultsToFalse(): void
    {
        self::assertFalse((new Capabilities(true, true, false))->transactionalOnly);
    }

    /** A plugin passes it by name, after checking the property is there. */
    public function testItCanBeSetByName(): void
    {
        self::assertTrue(property_exists(Capabilities::class, 'transactionalOnly'));
        self::assertTrue((new Capabilities(true, true, false, transactionalOnly: true))->transactionalOnly);
    }
}
