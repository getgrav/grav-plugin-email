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
class FlushQueueCommand extends ConsoleCommand
{
    /** @var array */
    protected $options = [];

    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('flush-queue')
            ->setAliases(['flushqueue'])
            ->setDescription('DEPRECATED: Flushes the email queue of any pending emails')
            ->setHelp('The <info>flush-queue</info> command flushes the email queue of any pending emails');
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
