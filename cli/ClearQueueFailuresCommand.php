<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Email\Email;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class FlushQueueCommand
 * @package Grav\Console\Cli\
 */
class ClearQueueFailuresCommand extends ConsoleCommand
{
    /**
     * @var array
     */
    protected $options = [];

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('clear-queue-failures')
            ->setAliases(['clearqueue'])
            ->addOption(
                'env',
                'e',
                InputOption::VALUE_OPTIONAL,
                'The environment to trigger a specific configuration. For example: localhost, mysite.dev, www.mysite.com'
            )
            ->setDescription('Clears any queue failures that have accumulated')
            ->setHelp('The <info>clear-queue-failures</info> command clears any queue failures that have accumulated');
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        // TODO: remove when requiring Grav 1.7+
        if (method_exists($this, 'initializeGrav')) {
            $this->initializeGrav();
        }

        $grav = Grav::instance();

        $this->output->writeln('');
        $this->output->writeln('<yellow>Current Configuration:</yellow>');
        $this->output->writeln('');

        dump($grav['config']->get('plugins.email'));

        $this->output->writeln('');

        require_once __DIR__ . '/../vendor/autoload.php';

        $output = Email::clearQueueFailures();

        $this->output->writeln('<green>' . $output . '</green>');

    }
}
