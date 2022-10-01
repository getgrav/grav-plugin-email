<?php
namespace Grav\Plugin\Email;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Language\Language;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Twig\Twig;
use Grav\Framework\Form\Interfaces\FormInterface;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;

class Email
{
    /** @var SymfonyMailer */
    protected $mailer;

    protected $log;

    public function __construct()
    {
        $this->initMailer();
        $this->initLog();

    }

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
     * @return Message
     */
    public function message(string $subject = null, string $body = null, string $contentType = null, string $charset = null): Message
    {
        $message = new Message();
        $message->subject($subject);
        if ($contentType === 'text/html') {
            $message->html($body);
        } else {
            $message->text($body);
        }

        return $message;
    }

    /**
     * Send email.
     *
     * @param Message $message
     * @param Envelope|null $envelope
     * @return int
     */
    public function send(Message $message, Envelope $envelope = null): int
    {
        $msg = 'sent email';
        try {
            $this->mailer->send($message->getEmail(), $envelope);
            $return = 1;
        } catch (TransportExceptionInterface $e) {
            $return = 0;
            $msg = $e->getMessage();
        }

        if ($this->debug()) {
            $this->log->addInfo($msg);
        }
        return $return;
    }

    /**
     * Build e-mail message.
     *
     * @param array $params
     * @param array $vars
     * @return Message
     */
    public function buildMessage(array $params, array $vars = []): Message
    {
        /** @var Twig $twig */
        $twig = Grav::instance()['twig'];
        $twig->init();

        /** @var Config $config */
        $config = Grav::instance()['config'];

        /** @var Language $language */
        $language = Grav::instance()['language'];

        $message = new Message();

        // Create message object.

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

        $params = $this->processParams($params, $vars);

        // Process parameters.
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'body':
                    if (is_string($value)) {
                      $this->processBody($message, $params, $vars, $twig, $value);
                    } elseif (is_array($value)) {
                        foreach ($value as $body_part) {
                            $params_part = $params;
                            if (isset($body_part['content_type'])) {
                                $params_part['content_type'] = $body_part['content_type'];
                            }
                            if (isset($body_part['body'])) {
                                $this->processBody($message, $params_part, $vars, $twig, $body_part['body']);
                            }
                        }
                    }
                    break;

                case 'subject':
                    if ($value) {
                        $message->subject($language->translate($value));
                    }
                    break;

                case 'to':
                case 'from':
                case 'cc':
                case 'bcc':
                case 'reply_to':
                    $recipients = $this->processRecipients($key, $params);
                    foreach ($recipients as $address) {
                        $message->$key($address);
                    }
                    break;
            }
        }

        return $message;
    }

    protected function processRecipients(string $type, array $params): array
    {
        $recipients = $params[$type] ?? Grav::instance()['config']->get('plugins.email.'.$type) ?? [];

        $list = [];

        if (!empty($recipients)) {
            if (is_array($recipients) && Utils::isAssoc($recipients)) {
                $list[] = $this->createAddress($recipients);
            } else {
                if (is_array($recipients[0])) {
                    foreach ($recipients as $recipient) {
                        $list[] = $this->createAddress($recipient);
                    }
                } else {
                    if (Utils::contains($recipients, ',')) {
                        $recipients = array_map('trim', explode(',', $recipients));
                        foreach ($recipients as $recipient) {
                            $list[] = $this->createAddress($recipient);
                        }
                    } else {
                        if (!Utils::contains($recipients, ['<','>']) && ($params[$type."_name"])) {
                            $recipients = [$recipients, $params[$type."_name"]];
                        }
                        $list[] = $this->createAddress($recipients);
                    }
                }
            }
        }

        return $list;
    }

    protected function createAddress($data): Address
    {
        if (is_string($data)) {
            preg_match('/^(.*)\<(.*)\>$/', $data, $matches);
            if (isset($matches[2])) {
                $email = trim($matches[2]);
                $name = trim($matches[1]);
            } else {
                $email = $data;
                $name = '';
            }
        } elseif (Utils::isAssoc($data)) {
            $first_key = array_key_first($data);
            if (filter_var($first_key, FILTER_VALIDATE_EMAIL)) {
                $email = $first_key;
                $name = $data[$first_key];
            } else {
                $email = $data['email'] ?? $data['mail'] ?? $data['address'] ?? '';
                $name = $data['name'] ?? $data['fullname'] ?? '';
            }
        } else {
            $email = $data[0] ?? '';
            $name = $data[1] ?? '';
        }
        return new Address($email, $name);
    }

    /**
     * @internal
     * @return null|SymfonyMailer
     */
    protected function initMailer()
    {
        if (!$this->enabled()) {
            return null;
        }
        if (!$this->mailer) {
            $transport = $this->getTransport();
            // Create the Mailer using your created Transport
            $this->mailer = new SymfonyMailer($transport);
        }
        return $this->mailer;
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function initLog()
    {
        $log_file = Grav::instance()['locator']->findResource('log://email.log', true, true);
        $this->log = new Logger('email');
        /** @var UniformResourceLocator $locator */
        $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
    }

    protected function processParams(array $params, array $vars = []): array
    {
        $twig = Grav::instance()['twig'];
        array_walk_recursive($params, function(&$value) use ($twig, $vars) {
            if (is_string($value)) {
                $value = $twig->processString($value, $vars);
            }
        });
        return $params;
    }

    protected function processBody($message, $params, $vars, $twig, $body)
    {
        if ($params['process_markdown'] && $params['content_type'] === 'text/html') {
            $body = (new Parsedown())->text($body);
        }

        if ($params['template']) {
            $body = $twig->processTemplate($params['template'], ['content' => $body] + $vars);
        }

        $content_type = !empty($params['content_type']) ? $twig->processString($params['content_type'], $vars) : null;

        if ($content_type === 'text/html') {
            $message->html($body);
        } else {
            $message->text($body);
        }
    }

    protected static function getTransport(): Transport\TransportInterface
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];
        $engine = $config->get('plugins.email.mailer.engine');
        $dsn = 'null://default';

        // Create the Transport and initialize it.
        switch ($engine) {
            case 'smtp':
                $options = $config->get('plugins.email.mailer.smtp');
                $dsn = 'smtp://';
                $auth = '';

                if (isset($options['user'])) {
                    $auth .= urlencode($options['user']);
                }
                if (isset($options['password'])) {
                    $auth .= ':'. urlencode($options['password']);
                }
                if (!empty($auth)) {
                    $dsn .= "$auth@";
                }
                if (isset($options['server'])) {
                    $dsn .= urlencode($options['server']);
                }
                if (isset($options['port'])) {
                    $dsn .= ":{$options['port']}";
                }
                if (isset($options['options'])) {
                    $dsn .= '?' . http_build_query($options['options']);
                }
                break;
            case 'native':
                $dsn = 'native://default';
                break;
            case 'sendmail':
                $dsn = 'sendmail://default';
                $bin = $config->get('plugins.email.mailer.sendmail.bin');
                if (isset($bin)) {
                    $dsn .= '?command=' . urlencode($bin);
                }
                break;
            default:
                $e = new Event(['engine' => $engine, ]);
                Grav::instance()->fireEvent('onEmailTransportDsn', $e);
                if (isset($e['dsn'])) {
                    $dsn = $e['dsn'];
                }
                break;
        }

        $transport = Transport::fromDsn($dsn);

        return $transport;
    }

    /**
     * @return void
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    public static function flushQueue() {}

    /**
     * @return void
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    public static function clearQueueFailures() {}

        /**
     * Creates an attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     * @return void
     */
    public function attachment($data = null, $filename = null, $contentType = null) {}

    /**
     * Creates an embedded attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     * @return void
     */
    public function embedded($data = null, $filename = null, $contentType = null) {}


    /**
     * Creates an image attachment.
     *
     * @param string $data
     * @param string $filename
     * @param string $contentType
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     * @return void
     */
    public function image($data = null, $filename = null, $contentType = null) {}

}
