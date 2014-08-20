<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use \Grav\Common\Twig;


class EmailPlugin extends Plugin
{
    /**
     * @var Email
     */
    protected $email;

    /**
     * @return array
     */
    public static function getSubscribedEvents() {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onFormProcessed' => ['onFormProcessed', 0]
        ];
    }

    /**
     * Initialize emailing.
     */
    public function onPluginsInitialized()
    {
        require_once __DIR__ . '/classes/email.php';
        require_once __DIR__ . '/vendor/autoload.php';

        $this->email = new Email();

        if ($this->email->enabled()) {
            $this->grav['Email'] = $this->email;
        }
    }

    /**
     * Send email when processing the form data.
     *
     * @param Form $form
     * @param string $task
     * @param array $params
     */
    public function onFormProcessed(Form $form, $task, $params)
    {
        if (!$this->email->enabled()) {
            return;
        }

        switch ($task) {
            case 'email':
                /** @var Twig $twig */
                $twig = $this->grav['twig'];
                $vars = array(
                    'form' => $form
                );

                if (!empty($params['from'])) {
                    $from = $twig->processString($params['from'], $vars);
                } else {
                    $from = $this->config->get('plugins.email.from');
                }

                if (!empty($params['to'])) {
                    $to = (array) $params['to'];
                    foreach ($to as &$address) {
                        $address = $twig->processString($address, $vars);
                    }
                } else {
                    $to = (array) $this->config->get('plugins.email.to');
                }
                $subject = !empty($params['subject']) ?
                    $twig->processString($params['subject'], $vars) : $form->page()->title();
                $body = !empty($params['body']) ?
                    $twig->processString($params['body'], $vars) : '{% include "forms/data.html.twig" %}';

                $message = $this->email->message($subject, $body)
                    ->setFrom($from)
                    ->setTo($to);

                $this->email->send($message);

                break;
        }
    }
}
