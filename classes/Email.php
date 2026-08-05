<?php
namespace Grav\Plugin\Email;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Language\Language;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Twig\Sandbox\SandboxConfig;
use Grav\Common\Twig\Twig;
use Grav\Framework\Form\Interfaces\FormInterface;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Twig\Extension\SandboxExtension;

class Email
{
    /**
     * Keys under `plugins.email` that email parameter Twig may read as
     * `config.plugins.email.*`. Addresses and formatting only, deliberately
     * no `mailer`, so transport credentials are not reachable. Covers both the
     * string and array forms an address setting can take.
     */
    private const PARAM_CONFIG_KEYS = [
        'to', 'to_name', 'from', 'from_name', 'cc', 'cc_name', 'bcc', 'bcc_name',
        'reply_to', 'reply_to_name', 'charset', 'content_type',
    ];

    /**
     * Filters allowed for email parameters on top of the content sandbox
     * allowlist. `raw` is required by the documented
     * `{{ config.site.emails.sales|raw }}` idiom: autoescape would turn a
     * `My Name <me@example.com>` address into `&lt;`, which the transport
     * rejects. Harmless here because the output is an email, not a DOM.
     */
    private const PARAM_EXTRA_FILTERS = ['raw'];

    /** @var Mailer */
    protected $mailer;

    /** @var TransportInterface */
    protected $transport;

    protected $log;

    protected $message;
    protected $debug;

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
     * @param string|null $subject
     * @param string|null $body
     * @param string|null $contentType
     * @param string|null $charset @deprecated
     * @return Message
     */
    public function message(?string $subject = null, ?string $body = null, ?string $contentType = null, ?string $charset = null): Message
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
     * @param  Message  $message
     * @param  Envelope|null  $envelope
     * @return int
     */
    public function send(Message $message, ?Envelope $envelope = null): int
    {
        try {
            $sent_msg = $this->transport->send($message->getEmail(), $envelope);
            $status = 1;
            $this->message = '✅';
            $this->debug = $sent_msg->getDebug();
        } catch (TransportExceptionInterface $e) {
            $status = 0;
            $this->message = '🛑 ' . $e->getMessage();
            $this->debug = $e->getDebug();

            // Capture HTTP transport errors with the raw response body for easier debugging (e.g., MailerSend 4xx/5xx).
            if ($e instanceof HttpTransportException) {
                try {
                    $response = $e->getResponse();
                    $statusCode = $response->getStatusCode();
                    $body = $response->getContent(false);

                    if (!empty($body)) {
                        $decoded = json_decode($body, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        }
                        $this->debug = trim((string)$this->debug) . "\n-- HTTP response body (status {$statusCode}) --\n" . $body;

                        // If the exception message was empty, include a short summary in the user-facing message.
                        if (trim($e->getMessage()) === '') {
                            $this->message = sprintf('🛑 HTTP %d error while sending email. See debug for response body.', $statusCode);
                        }
                    }
                } catch (\Throwable $httpError) {
                    $this->debug = trim((string)$this->debug) . "\n-- Failed to read HTTP error response --\n" . $httpError->getMessage();
                }
            }
        }

        if ($this->debug()) {
            $log_msg = "Email sent to %s at %s -> %s\n%s";
            $to = $this->jsonifyRecipients($message->getEmail()->getTo());
            $message = sprintf($log_msg, $to, date('Y-m-d H:i:s'), $this->message, $this->debug);
            $this->log->info($message);
        }

        return $status;
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

        // Create message object.
        $message = new Message();
        $headers = $message->getEmail()->getHeaders();
        $email = $message->getEmail();

        // Extend parameters with defaults.
        $defaults = [
            'bcc' => $config->get('plugins.email.bcc', []),
            'bcc_name' => $config->get('plugins.email.bcc_name'),
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

        foreach ($defaults as $key => $value) {
            if (!key_exists($key, $params)) {
                $params[$key] = $value;
            }
        }

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
                            if (isset($body_part['template'])) {
                                $params_part['template'] = $body_part['template'];
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
                    if ($recipients = $this->processRecipients($key, $params)) {
                        $key = $key === 'reply_to' ? 'replyTo' : $key;
                        $email->$key(...$recipients);
                    }
                    break;
                case 'tags':
                    foreach ((array) $value as $tag) {
                        if (is_string($tag)) {
                            $headers->add(new TagHeader($tag));
                        }
                    }
                    break;
                case 'metadata':
                    foreach ((array) $value as $k => $v) {
                        if (is_string($k) && is_string($v)) {
                            $headers->add(new MetadataHeader($k, $v));
                        }
                    }
                    break;
            }
        }

        return $message;
    }

    /**
     * @param string $type
     * @param array $params
     * @return array
     */
    protected function processRecipients(string $type, array $params): array
    {
        if (array_key_exists($type, $params) && $params[$type] === null) {
            return [];
        }

        $recipients = $params[$type] ?? Grav::instance()['config']->get('plugins.email.'.$type) ?? [];

        $list = [];

        if (!empty($recipients)) {
            if (is_array($recipients)) {
                if (Utils::isAssoc($recipients) || (count($recipients) ===2 && $this->isValidEmail($recipients[0]) && !$this->isValidEmail($recipients[1]))) {
                    $address = $this->createAddress($recipients);
                    if ($address !== null) {
                        $list[] = $address;
                    }
                } else {
                    foreach ($recipients as $recipient) {
                        $address = $this->createAddress($recipient);
                        if ($address !== null) {
                            $list[] = $address;
                        }
                    }
                }
            } else {
                if (is_string($recipients) && Utils::contains($recipients, ',')) {
                    $recipients = array_map('trim', explode(',', $recipients));
                    foreach ($recipients as $recipient) {
                        $address = $this->createAddress($recipient);
                        if ($address !== null) {
                            $list[] = $address;
                        }
                    }
                } else {
                    if (!Utils::contains($recipients, ['<','>']) && (isset($params[$type."_name"]))) {
                        $recipients = [$recipients, $params[$type."_name"]];
                    }
                    $address = $this->createAddress($recipients);
                    if ($address !== null) {
                        $list[] = $address;
                    }
                }
            }
        }

        return $list;
    }

    /**
     * @param $data
     * @return Address|null
     */
    protected function createAddress($data): ?Address
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

        // Skip empty or invalid email addresses
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return new Address($email, $name);
    }

    /**
     * @return null|Mailer
     * @internal
     */
    protected function initMailer(): ?Mailer
    {
        if (!$this->enabled()) {
            return null;
        }
        if (!$this->mailer) {
            $this->transport = $this->getTransport();
            // Create the Mailer using your created Transport
            $this->mailer = new Mailer($this->transport);
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

    /**
     * Render the email action's parameters (subject, body, recipients, ...) as
     * Twig.
     *
     * These are NOT operator-only configuration. A page's
     * `form.process.email.*` front matter is authorable by anyone with
     * page-write access, so every string here is editor content and is
     * rendered under the Twig content sandbox. (GHSA-gh8j-q67c-j53f)
     *
     * @param array $params
     * @param array $vars
     * @return array
     */
    protected function processParams(array $params, array $vars = []): array
    {
        $twig = Grav::instance()['twig'];
        $twig->init();

        // Add twig vars to the context
        $vars += $twig->twig_vars;

        // On Grav 2.0 the sandbox replaces `config` with a facade that denies
        // everything unless the operator opted into config access, which would
        // break the documented `{{ config.site.emails.sales }}` and
        // `{{ config.plugins.email.to }}` idioms. Supply the narrow slice those
        // idioms need instead; the sandbox leaves a caller-supplied `config`
        // alone.
        //
        // Grav 1.7 has no Twig content sandbox, so nothing replaces `config`
        // there and narrowing it ourselves would only take away access those
        // sites already have. Leave 1.7 exactly as it was.
        if ($this->sandboxAvailable()) {
            $vars['config'] = $this->buildParamConfig();
            $vars = $this->filterParamVars($vars);
        }

        array_walk_recursive($params, function(&$value) use ($twig, $vars) {
            if (is_string($value)) {
                $value = $this->processTwigString($twig, $value, $vars);
            }
        });
        return $params;
    }

    /**
     * Is Grav's Twig content sandbox available and switched on?
     *
     * True on Grav 2.0 with `security.twig_sandbox.enabled`, false on Grav 1.7,
     * which has no content sandbox at all, and false when an operator has
     * turned it off. When false this plugin renders email parameters exactly
     * as it always has, which is also why the 1.7 line is out of scope for
     * GHSA-gh8j-q67c-j53f: reaching those parameters there needs a
     * publisher-level account.
     *
     * @return bool
     */
    protected function sandboxAvailable(): bool
    {
        if (!class_exists(SandboxExtension::class) || !class_exists(SandboxConfig::class)) {
            return false;
        }

        $twig = Grav::instance()['twig'];

        return isset($twig->twig) && $twig->twig->hasExtension(SandboxExtension::class);
    }

    /**
     * The `config` value exposed to email parameter Twig.
     *
     * A plain array, not the real Config, holding only what email parameters
     * legitimately read: the site configuration (where operators keep their own
     * addresses, e.g. `site.emails.sales`) and this plugin's own address
     * fields. Everything else is simply absent: `system.*`, other plugins, and
     * this plugin's own mailer credentials.
     *
     * The site subtree is read through {@see SandboxConfig} so that
     * `security.twig_sandbox.config_denied_paths` is honoured here too: an
     * operator who parks secrets under, say, `site.integrations` and denies
     * that path gets it redacted in email parameters as well.
     *
     * @return array
     */
    protected function buildParamConfig(): array
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];

        $denied = (array) $config->get('security.twig_sandbox.config_denied_paths', []);
        $filtered = new SandboxConfig($config, $denied);

        $email = [];
        foreach (self::PARAM_CONFIG_KEYS as $key) {
            $value = $config->get('plugins.email.' . $key);
            if ($value !== null) {
                $email[$key] = $value;
            }
        }

        return [
            'site' => $filtered->get('site', []),
            'plugins' => ['email' => $email],
        ];
    }

    /**
     * Filter the raw `system`, `site` and `theme` variables inherited from
     * `Twig::$twig_vars`.
     *
     * Narrowing `config` is not enough on its own. Those three are also
     * exposed as top-level variables, they are plain PHP arrays, and Twig's
     * sandbox has no jurisdiction over array key access, so
     * `{{ system.cache.redis.password }}` in a form's `process.email.subject`
     * renders the live value no matter how strict the policy is
     * (GHSA-p597-crqc-m349).
     *
     * Grav 2.0.16 does this for page content and `@Var:` strings inside
     * `Twig::processPage()` / `processString()`. This render calls
     * `$twig->twig->render()` directly, so it has to apply the same filter
     * itself, driven by the same `security.twig_sandbox.config_denied_paths`
     * list so operators only maintain one.
     *
     * @param array $vars
     * @return array
     */
    protected function filterParamVars(array $vars): array
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];

        $filter = new SandboxConfig(
            $config,
            (array) $config->get('security.twig_sandbox.config_denied_paths', [])
        );

        foreach (['system', 'site', 'theme'] as $key) {
            if (array_key_exists($key, $vars)) {
                $vars[$key] = $filter->get($key, []);
            }
        }

        return $vars;
    }

    /**
     * Render a single email parameter string under the Twig content sandbox.
     *
     * The template is registered as `@EmailVar:`, which GravSourcePolicy
     * sandboxes. Keeping that distinct name matters: Twig caches compiled
     * templates by name and runs the sandbox tag/filter check once per compiled
     * template, so sharing the `@Var:` namespace used by page content would let
     * a string compiled here keep the relaxed policy below when the same string
     * is later rendered as page content.
     *
     * @param Twig $twig
     * @param string $string
     * @param array $vars
     * @return string
     */
    protected function processTwigString(Twig $twig, string $string, array $vars): string
    {
        // Skip if no Twig syntax
        if (strpos($string, '{{') === false && strpos($string, '{%') === false) {
            return $string;
        }

        $sandbox = null;
        $policy = null;

        if ($this->sandboxAvailable()) {
            $sandbox = $twig->twig->getExtension(SandboxExtension::class);
            $policy = $sandbox->getSecurityPolicy();
        }

        if ($sandbox && $policy) {
            // Same restrictions as page content, plus the filters an email
            // needs in order to emit an unescaped address. Restored in the
            // finally below: this mutates the one shared SandboxExtension, so
            // an escaping exception must not leave the relaxed policy live for
            // the rest of the request.
            $sandbox->setSecurityPolicy(new EmailParamPolicy($policy, self::PARAM_EXTRA_FILTERS));
        }

        try {
            // Use Grav's setTemplate method which uses the loaderArray
            $name = '@EmailVar:' . md5($string);
            $twig->setTemplate($name, $string);

            return $twig->twig->render($name, $vars);
        } catch (\Exception $e) {
            // A trusted email string (body, subject, recipient, etc.) failed to
            // render. This is almost always a Twig syntax error or an
            // unresolved {% extends %}/{% include %} in the template. We keep
            // sending so one bad string doesn't block the whole notification,
            // but the failure used to be completely silent, which made it very
            // hard to trace (the raw, unrendered Twig just dropped into the
            // email). Log it loudly to both the email log and the main Grav log
            // with the Twig error and a snippet of the offending string.
            $snippet = strlen($string) > 200 ? substr($string, 0, 200) . '…' : $string;
            $report = sprintf('Twig render failed, sending raw string: %s | source: %s', $e->getMessage(), $snippet);

            $this->log->error($report);
            Grav::instance()['log']->error('plugin-email: ' . $report);

            return $string;
        } finally {
            if ($sandbox && $policy) {
                $sandbox->setSecurityPolicy($policy);
            }
        }
    }

    /**
     * @param $message
     * @param $params
     * @param $vars
     * @param $twig
     * @param $body
     * @return void
     */
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

    /**
     * @return TransportInterface
     */
    protected static function getTransport(): Transport\TransportInterface
    {
        /** @var Config $config */
        $config = Grav::instance()['config'];
        $engine = $config->get('plugins.email.mailer.engine');
        $dsn = 'null://default';


        // Create the Transport and initialize it.
        switch ($engine) {
            case 'smtps':
            case 'smtp':
                $options = $config->get('plugins.email.mailer.smtp');
                $dsn = $engine . '://';
                $auth = '';

                if (isset($options['encryption']) && $options['encryption'] === 'none') {
                    $options['options']['verify_peer'] = 0;
                }
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
            case 'mail':
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

        if ($dsn instanceof TransportInterface) {
            $transport = $dsn;
        } else {
           $transport = Transport::fromDsn($dsn) ;
        }

        return $transport;
    }

    /**
     * Get any message from the last send attempt
     * @return string|null
     */
    public function getLastSendMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get any debug information from the last send attempt
     * @return string|null
     */
    public function getLastSendDebug(): ?string
    {
        return $this->debug;
    }

    /**
     * @param array $recipients
     * @return string
     */
    protected function jsonifyRecipients(array $recipients): string
    {
        $json = [];
        foreach ($recipients as $recipient) {
            $json[] = str_replace('"', "", $recipient->toString());
        }
        return json_encode($json);
    }

    protected function isValidEmail($email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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
