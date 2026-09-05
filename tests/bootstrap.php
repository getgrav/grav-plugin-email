<?php

/**
 * Test bootstrap for the Email plugin.
 *
 * PHPUnit lives in `tests/vendor`, installed from `tests/composer.json`, so
 * that the `vendor` directory the plugin ships never carries a development
 * package. The plugin's own autoloader is loaded alongside it, which is what
 * brings in `Grav\Plugin\Email\*` and the Symfony Mailer and Mime classes those
 * are built on.
 *
 * Nothing here boots Grav. These are unit tests of the plugin's own classes;
 * anything that needs a live `Grav::instance()` belongs in a site, not here.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../vendor/autoload.php';
