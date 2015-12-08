<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Twig;
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

                // Build message
                $message = $this->buildMessage($params, $vars);

                // Send e-mail
                $this->email->send($message);
                break;
        }
    }

    /**
     * Build e-mail message.
     *
     * @param array $params
     * @param array $vars
     * @return \Swift_Message
     */
    protected function buildMessage(array $params, array $vars = array())
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        // Extend parameters with defaults.
        $params += array(
            'body' => '{% include "forms/data.html.twig" %}',
            'from' => $this->config->get('plugins.email.from'),
            'content_type' => $this->config->get('plugins.email.content_type', 'text/html'),
            'reply_to' => array(),
            'subject' => !empty($vars['form']) && $vars['form'] instanceof Form ? $vars['form']->page()->title() : null,
            'to' => $this->config->get('plugins.email.to'),
        );

        // Create message object.
        $message = $this->email->message();

        // Process parameters.
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'bcc':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addBcc($address->mail, $address->name);
                    }
                    break;

                case 'body':
                    $message->setBody($twig->processString($value, $vars));
                    break;

                case 'cc':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addCc($address->mail, $address->name);
                    }
                    break;

                case 'content_type':
                    if (!empty($value)) {
                        $message->setContentType($twig->processString($value, $vars));
                    }
                    break;

                case 'from':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addFrom($address->mail, $address->name);
                    }
                    break;

                case 'reply_to':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addReplyTo($address->mail, $address->name);
                    }
                    break;

                case 'subject':
                    $message->setSubject($twig->processString($value, $vars));
                    break;

                case 'to':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addTo($address->mail, $address->name);
                    }
                    break;
            }
        }

        return $message;
    }

    /**
     * Return parsed e-mail address value.
     *
     * @param $value
     * @param array $vars
     * @return array
     */
    protected function parseAddressValue($value, array $vars = array())
    {
        $parsed = array();

        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        // Single e-mail address string
        if (is_string($value)) {
            $parsed[] = (object) array(
                'mail' => $twig->processString($value, $vars),
                'name' => null,
            );
        }

        else {
            // Cast value as array
            $value = (array) $value;

            // Single e-mail address array
            if (!empty($value['mail'])) {
                $parsed[] = (object) array(
                  'mail' => $twig->processString($value['mail'], $vars),
                  'name' => !empty($value['name']) ? $twig->processString($value['name'], $vars) : NULL,
                );
            }

            // Multiple addresses (either as strings or arrays)
            elseif (!(empty($value['mail']) && !empty($value['name']))) {
                foreach ($value as $y => $itemx) {
                    $addresses = $this->parseAddressValue($itemx, $vars);

                    if (($address = reset($addresses))) {
                        $parsed[] = $address;
                    }
                }
            }
        }

        return $parsed;
    }
}
