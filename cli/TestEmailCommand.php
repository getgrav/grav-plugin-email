<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Plugin\Email;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class TestEmailCommand
 * @package Grav\Console\Cli\
 */
class TestEmailCommand extends ConsoleCommand
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
            ->setName('test-email')
            ->setAliases(['testemail'])
            ->addOption(
                'to',
                't',
                InputOption::VALUE_REQUIRED,
                'An email address to send the email to'
            )
            ->addOption(
                'env',
                'e',
                InputOption::VALUE_OPTIONAL,
                'The environment to trigger a specific configuration. For example: localhost, mysite.dev, www.mysite.com'
            )
            ->addOption(
                'subject',
                's',
                InputOption::VALUE_OPTIONAL,
                'A subject for the email'
            )
            ->addOption(
                'body',
                'b',
                InputOption::VALUE_OPTIONAL,
                'The body of the email'
            )
            ->setDescription('Sends a test email using the plugin\'s configuration')
            ->setHelp('The <info>test-email</info> command sends a test email using the plugin\'s configuration');
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
//        $environment = $this->input->getOption('env');
        $environment = 'grav.rhuk.net';

        $grav = Grav::instance();

        $grav['setup']->def('environment', $environment);
        $grav['setup']->def('streams.schemes.environment.prefixes', ['' => ["user://{$environment}"]]);

        $grav['setup']->init();
        $grav['config']->init();

        $this->output->writeln('');
        $this->output->writeln('<yellow>Current Configuration:</yellow>');
        $this->output->writeln('');

        print_r($grav['config']->get('plugins.email'));

        $this->output->writeln('');

        require_once __DIR__ . '/../classes/email.php';
        require_once __DIR__ . '/../vendor/autoload.php';

        /** @var Email $email */
        $email = new Email();

        $email_from = $grav['config']->get('plugins.email.from');
        $email_to = $this->input->getOption('to');
        $subject = $this->input->getOption('subject');
        $body = $this->input->getOption('body');

        if (!$subject) {
            $subject = 'Testing Grav Email Plugin';
        }

        if (!$body) {
            $body = '<div style="font-family:helvetica;arial;"><p>This is an email that is intended to test the validity of the email plugin settings.</p>The fact that you are reading this indicates that email settings were good!</p><p><strong>Well done!</strong></p></div>';
        }

        if ($email) {
            $message = $email->message();
            $message->setContentType('text/html');
            $message->setTo($email_to);
            $message->setFrom($email_from);
            $message->setSubject($subject);
            $message->setBody($body);

            $email->send($message);

            $this->output->writeln("<green>Message sent sucessfully!</green>");
        } else {
            $this->output->writeln("<red>Email object not found, probably not enabled.</red>");
        }
    }
}
