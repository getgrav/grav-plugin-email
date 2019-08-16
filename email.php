<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\Email\Email;
use RocketTheme\Toolbox\Event\Event;

class EmailPlugin extends Plugin
{
    /**
     * @var Email
     */
    protected $email;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'      => ['onPluginsInitialized', 0],
            'onFormProcessed'           => ['onFormProcessed', 0],
            'onTwigTemplatePaths'       => ['onTwigTemplatePaths', 0],
            'onSchedulerInitialized'    => ['onSchedulerInitialized', 0],
        ];
    }

    /**
     * Initialize emailing.
     */
    public function onPluginsInitialized()
    {
        require_once __DIR__ . '/vendor/autoload.php';

        $this->email = new Email();

        if ($this->email->enabled()) {
            $this->grav['Email'] = $this->email;
        }
    }

    /**
     * Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths()
    {
        $twig = $this->grav['twig'];
        $twig->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Send email when processing the form data.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->email->enabled()) {
            return;
        }

        switch ($action) {
            case 'email':
                // Prepare Twig variables
                $vars = array(
                    'form' => $form
                );

                // Copy files now, we need those.
                // TODO: needs an update
                $form->legacyUploads();
                $form->copyFiles();

                $this->grav->fireEvent('onEmailSend', new Event(['params' => &$params, 'vars' => &$vars]));

                if ($this->isAssocArray($params)) {
                    $this->sendFormEmail($form, $params, $vars);
                } else {
                    foreach ($params as $email) {
                        $this->sendFormEmail($form, $email, $vars);
                    }
                }

                break;
        }
    }

    /**
     * Add index job to Grav Scheduler
     * Requires Grav 1.6.0 - Scheduler
     */
    public function onSchedulerInitialized(Event $e)
    {
        if ($this->config->get('plugins.email.queue.enabled')) {

            /** @var Scheduler $scheduler */
            $scheduler = $e['scheduler'];
            $at = $this->config->get('plugins.email.queue.flush_frequency');
            $logs = 'logs/email-queue.out';
            $job = $scheduler->addFunction('Grav\Plugin\Email\Email::flushQueue', [], 'email-flushqueue');
            $job->at($at);
            $job->output($logs);
            $job->backlink('/plugins/email');
        }
    }

    protected function sendFormEmail($form, $params, $vars)
    {
        // Build message
        $message = $this->email->buildMessage($params, $vars);

        if (isset($params['attachments'])) {
            $filesToAttach = (array)$params['attachments'];
            if ($filesToAttach) foreach ($filesToAttach as $fileToAttach) {
                $filesValues = $form->value($fileToAttach);

                if ($filesValues) foreach($filesValues as $fileValues) {
                    if (isset($fileValues['file'])) {
                        $filename = $fileValues['file'];
                    } else {
                        $filename = ROOT_DIR . $fileValues['path'];
                    }

                    try {
                        $message->attach(\Swift_Attachment::fromPath($filename));
                    } catch (\Exception $e) {
                        // Log any issues
                        $this->grav['log']->error($e->getMessage());
                    }
                }
            }
        }

        // Send e-mail
        $this->email->send($message);
    }

    protected function isAssocArray(array $arr)
    {
        if (array() === $arr) return false;
        $keys = array_keys($arr);
        $index_keys = range(0, count($arr) - 1);
        return $keys !== $index_keys;
    }

}
