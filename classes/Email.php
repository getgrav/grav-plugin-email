<?php
namespace Grav\Plugin\Email;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Twig\Twig;
use Grav\Framework\Form\Interfaces\FormInterface;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use Symfony\Component\Mime\Email as SymfonyEmail;

class Email
{
    /**
     * @var Mailer
     */
    protected $mailer;

    protected $logger;

    /**
     * Returns true if emails have been enabled in the system.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return Grav::instance()['config']->get('plugins.email.mailer.engine') !== 'none';
    }

    /**
     * Returns true if debugging on emails has been enabled.
     *
     * @return bool
     */
    public static function debug(): bool
    {
        return Grav::instance()['config']->get('plugins.email.debug') == 'true';
    }

    /**
     * Creates an email message.
     *
     * @param string $subject
     * @param string $body
     * @param string $contentType
     * @param string $charset
     * @return SymfonyEmail
     */
    public function message($subject = null, $body = null, $contentType = null, $charset = null)
    {
        $email = (new SymfonyEmail())->subject($subject);
        if ($contentType === 'text/html') {
            $email->html($body);
        } else {
            $email->text($body);
        }

        return $email;
    }

    /**
     * Creates an attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @return \Swift_Attachment
     */
    public function attachment($data = null, $filename = null, $contentType = null)
    {
        return new \Swift_Attachment($data, $filename, $contentType);
    }

    /**
     * Creates an embedded attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @return \Swift_EmbeddedFile
     */
    public function embedded($data = null, $filename = null, $contentType = null)
    {
        return new \Swift_EmbeddedFile($data, $filename, $contentType);
    }

    /**
     * Creates an image attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @return \Swift_Image
     */
    public function image($data = null, $filename = null, $contentType = null)
    {
        return new \Swift_Image($data, $filename, $contentType);
    }

    /**
     * Send email.
     *
     * @param \Swift_Message $message
     * @param array|null $failedRecipients
     * @return int
     */
    public function send($message, &$failedRecipients = null)
    {
        $mailer = $this->getMailer();

        $result = $mailer ? $mailer->send($message, $failedRecipients) : 0;

        // Check if emails and debugging are both enabled.
        if ($mailer && $this->debug()) {

            $log = new Logger('email');
            $locator = Grav::instance()['locator'];
            $log_file = $locator->findResource('log://email.log', true, true);
            $log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));

            // Append the SwiftMailer log to the log.
            $log->addDebug($this->getLogs());
        }

        return $result;
    }

    /**
     * Build e-mail message.
     *
     * @param array $params
     * @param array $vars
     * @return \Swift_Message
     */
    public function buildMessage(array $params, array $vars = [])
    {
        /** @var Twig $twig */
        $twig = Grav::instance()['twig'];
        $twig->init();

        /** @var Config $config */
        $config = Grav::instance()['config'];

        /** @var Language $language */
        $language = Grav::instance()['language'];

        // Create message object.
        $message = $this->message();

        // Extend parameters with defaults.
        $params += [
            'bcc' => $config->get('plugins.email.bcc', []),
            'body' => $config->get('plugins.email.body', '{% include "forms/data.html.twig" %}'),
            'cc' => $config->get('plugins.email.cc', []),
            'cc_name' => $config->get('plugins.email.cc_name'),
            'charset' =>  $config->get('plugins.email.charset', 'utf-8'),
            'from' => $config->get('plugins.email.from'),
            'from_name' => $config->get('plugins.email.from_name'),
            'content_type' => $config->get('plugins.email.content_type', 'text/html'),
            'reply_to' => $config->get('plugins.email.reply_to', []),
            'reply_to_name' => $config->get('plugins.email.reply_to_name'),
            'subject' => !empty($vars['form']) && $vars['form'] instanceof FormInterface ? $vars['form']->page()->title() : null,
            'to' => $config->get('plugins.email.to'),
            'to_name' => $config->get('plugins.email.to_name'),
            'process_markdown' => false,
            'template' => false,
            'message' => $message
        ];

        if (!$params['to']) {
            throw new \RuntimeException($language->translate('PLUGIN_EMAIL.PLEASE_CONFIGURE_A_TO_ADDRESS'));
        }
        if (!$params['from']) {
            throw new \RuntimeException($language->translate('PLUGIN_EMAIL.PLEASE_CONFIGURE_A_FROM_ADDRESS'));
        }

        // make email configuration available to templates
        $vars += [
            'email' => $params,
        ];

        // Process parameters.
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'body':
                    if (is_string($value)) {
                        if (strpos($value, '{{') !== false || strpos($value, '{%') !== false) {
                            $body = $twig->processString($value, $vars);
                        } else {
                            $body = $value;
                        }

                        if ($params['process_markdown'] && $params['content_type'] === 'text/html') {
                            $parsedown = new Parsedown();
                            $body = $parsedown->text($body);
                        }

                        if ($params['template']) {
                            $body = $twig->processTemplate($params['template'], ['content' => $body] + $vars);
                        }

                        $content_type = !empty($params['content_type']) ? $twig->processString($params['content_type'], $vars) : null;
                        $charset = !empty($params['charset']) ? $twig->processString($params['charset'], $vars) : null;

                        $message->setBody($body, $content_type, $charset);
                    } elseif (is_array($value)) {
                        foreach ($value as $body_part) {
                            $body_part += [
                                'charset' => $params['charset'],
                                'content_type' => $params['content_type'],
                            ];

                            $body = !empty($body_part['body']) ? $twig->processString($body_part['body'], $vars) : null;

                            if ($params['process_markdown'] && $body_part['content_type'] === 'text/html') {
                                $parsedown = new Parsedown();
                                $body = $parsedown->text($body);
                            }

                            if (isset($body_part['template'])) {
                                $body = $twig->processTemplate($body_part['template'], ['content' => $body] + $vars);
                            }

                            $content_type = !empty($body_part['content_type']) ? $twig->processString($body_part['content_type'], $vars) : null;
                            $charset = !empty($body_part['charset']) ? $twig->processString($body_part['charset'], $vars) : null;

                            if (!$message->getBody()) {
                                $message->setBody($body, $content_type, $charset);
                            }
                            else {
                                $message->addPart($body, $content_type, $charset);
                            }
                        }
                    }
                    break;

                case 'subject':
                    if ($value) {
                        $message->setSubject($twig->processString($language->translate($value), $vars));
                    }
                    break;

                case 'to':
                    if (is_string($value) && !empty($params['to_name'])) {
                        $value = [
                            'mail' => $twig->processString($value, $vars),
                            'name' => $twig->processString($params['to_name'], $vars),
                        ];
                    }

                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addTo($address->mail, $address->name);
                    }
                    break;

                case 'cc':
                    if (is_string($value) && !empty($params['cc_name'])) {
                        $value = [
                            'mail' => $twig->processString($value, $vars),
                            'name' => $twig->processString($params['cc_name'], $vars),
                        ];
                    }

                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addCc($address->mail, $address->name);
                    }
                    break;

                case 'bcc':
                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addBcc($address->mail, $address->name);
                    }
                    break;

                case 'from':
                    if (is_string($value) && !empty($params['from_name'])) {
                        $value = [
                            'mail' => $twig->processString($value, $vars),
                            'name' => $twig->processString($params['from_name'], $vars),
                        ];
                    }

                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addFrom($address->mail, $address->name);
                    }
                    break;

                case 'reply_to':
                    if (is_string($value) && !empty($params['reply_to_name'])) {
                        $value = [
                            'mail' => $twig->processString($value, $vars),
                            'name' => $twig->processString($params['reply_to_name'], $vars),
                        ];
                    }

                    foreach ($this->parseAddressValue($value, $vars) as $address) {
                        $message->addReplyTo($address->mail, $address->name);
                    }
                    break;

            }
        }

        return $message;
    }

    /**
     * Return parsed e-mail address value.
     *
     * @param string|string[] $value
     * @param array $vars
     * @return array
     */
    public function parseAddressValue($value, array $vars = [])
    {
        $parsed = [];

        /** @var Twig $twig */
        $twig = Grav::instance()['twig'];

        // Single e-mail address string
        if (is_string($value)) {
            $parsed[] = (object) [
                'mail' => $twig->processString($value, $vars),
                'name' => null,
            ];
        }

        else {
            // Cast value as array
            $value = (array) $value;

            // Single e-mail address array
            if (!empty($value['mail'])) {
                $parsed[] = (object) [
                    'mail' => $twig->processString($value['mail'], $vars),
                    'name' => !empty($value['name']) ? $twig->processString($value['name'], $vars) : NULL,
                ];
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

    /**
     * Return debugging logs if enabled
     *
     * @return string
     */
    public function getLogs()
    {
        if ($this->debug()) {
            return $this->logger->dump();
        }
        return '';
    }

    /**
     * @internal
     * @return null|\Swift_Mailer
     */
    protected function getMailer()
    {
        if (!$this->enabled()) {
            return null;
        }

        if (!$this->mailer) {
            /** @var Config $config */
            $config = Grav::instance()['config'];
            $queue_enabled = $config->get('plugins.email.queue.enabled');

            $transport = $this->getTransport();

            // Create the Mailer using your created Transport
            $this->mailer = new \Swift_Mailer($transport);

            // Register the logger if we're debugging.
            if ($this->debug()) {
                $this->logger = new \Swift_Plugins_Loggers_ArrayLogger();
                $this->mailer->registerPlugin(new \Swift_Plugins_LoggerPlugin($this->logger));
            }
        }

        return $this->mailer;
    }


    /**
     * @return string
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    public static function flushQueue()
    {
        return 'Switched from Swiftmailer to Symfony/Mailer - No longer supported';

    }

    /**
     * @return void
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    public static function clearQueueFailures()
    {

    }

    protected static function getTransport()
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];

        $engine = $config->get('plugins.email.mailer.engine');

        // Create the Transport and initialize it.
        switch ($engine) {
            case 'smtp':
                $transport = new \Swift_SmtpTransport();

                $options = $config->get('plugins.email.mailer.smtp');
                if (!empty($options['server'])) {
                    $transport->setHost($options['server']);
                }
                if (!empty($options['port'])) {
                    $transport->setPort($options['port']);
                }
                if (!empty($options['encryption']) && $options['encryption'] !== 'none') {
                    $transport->setEncryption($options['encryption']);
                }
                if (!empty($options['user'])) {
                    $transport->setUsername($options['user']);
                }
                if (!empty($options['password'])) {
                    $transport->setPassword($options['password']);
                }
                if (!empty($options['auth_mode'])) {
                    $transport->setAuthMode($options['auth_mode']);
                }
                break;
            case 'sendmail':
            default:
                $options = $config->get('plugins.email.mailer.sendmail');
                $bin = !empty($options['bin']) ? $options['bin'] : '/usr/sbin/sendmail';
                $transport = new \Swift_SendmailTransport($bin);
                break;
        }

        return $transport;
    }
}
