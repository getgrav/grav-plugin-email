<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Email\Email;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class FlushQueueCommand
 * @package Grav\Console\Cli\
 * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
 */
class ClearQueueFailuresCommand extends ConsoleCommand
{
    /** @var array */
    protected $options = [];

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('clear-queue-failures')
            ->setAliases(['clearqueue'])
            ->setDescription('DEPRECATED: Clears any queue failures that have accumulated')
            ->setHelp('The <info>clear-queue-failures</info> command clears any queue failures that have accumulated');
    }

    /**
     * @return int
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    protected function serve()
    {
        return 0;
    }
}
