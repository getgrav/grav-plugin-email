<?php

/**
 * The two Grav classes `email.php` needs before `EmailPlugin` can be declared
 * and its form handler run, for tests that drive the plugin class itself.
 *
 * The suite never boots Grav (see bootstrap.php), so `Grav\Common\Plugin` and
 * RocketTheme's `Event` are not on the autoloader. These carry only what
 * `EmailPlugin::sendFormEmail()` touches, and are declared only when the real
 * classes are absent.
 */

namespace Grav\Common {
    if (!class_exists(Plugin::class)) {
        class Plugin
        {
            /** @var \ArrayAccess|null The test's stand-in for the Grav container. */
            protected $grav;
        }
    }
}

namespace RocketTheme\Toolbox\Event {
    if (!class_exists(Event::class)) {
        class Event extends \ArrayObject
        {
            /** @var bool */
            public $stopped = false;

            public function stopPropagation(): void
            {
                $this->stopped = true;
            }
        }
    }
}
