<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Data\Data;
use Grav\Common\Data\ValidationException;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\Email\Email;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Mailer\Exception\TransportException;

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
            'onAdminSave'               => ['onAdminSave', 0],
            'onApiRegisterRoutes'       => ['onApiRegisterRoutes', 0],
        ];
    }

    /**
     * Register API routes for sending emails via the Grav API plugin.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $routes->post('/email/send', [\Grav\Plugin\Email\EmailApiController::class, 'send']);
        $routes->post('/email/test', [\Grav\Plugin\Email\EmailApiController::class, 'test']);
    }

    /**
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize emailing.
     */
    public function onPluginsInitialized()
    {
        $this->email = new Email();

        if ($this->email::enabled()) {
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
     * Force compile during save if admin plugin save
     *
     * @param Event $event
     */
    public function onAdminSave(Event $event)
    {
        /** @var Data $obj */
        $obj = $event['object'];

        if ($obj instanceof Data && $obj->blueprints()->getFilename() === 'email/blueprints') {
            $current_pw = $this->grav['config']->get('plugins.email.mailer.smtp.password');
            $new_pw = $obj->get('mailer.smtp.password');
            if (!empty($current_pw) && empty($new_pw)) {
                $obj->set('mailer.smtp.password', $current_pw);
            }

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
                    'form' => $form,
                    'page' => $this->grav['page']
                );

                // Copy files now, we need those.
                // TODO: needs an update
                $form->legacyUploads();
                $form->copyFiles();

                $this->grav->fireEvent('onEmailSend', new Event(['params' => &$params, 'vars' => &$vars]));

                if (Utils::isAssoc($params)) {
                    $this->sendFormEmail($form, $params, $vars, $event);
                } else {
                    foreach ($params as $email) {
                        $this->sendFormEmail($form, $email, $vars, $event);
                    }
                }

                break;
        }
    }

    protected function sendFormEmail($form, $params, $vars, $event)
    {
        // Build message
        $message = $this->email->buildMessage($params, $vars);
        $locator = $this->grav['locator'];

        if (isset($params['attachments'])) {
            $filesToAttach = (array)$params['attachments'];
            if ($filesToAttach) foreach ($filesToAttach as $fileToAttach) {
                $filesValues = $form->value($fileToAttach);

                if ($filesValues) foreach($filesValues as $fileValues) {
                    if (isset($fileValues['file'])) {
                        $filename = $fileValues['file'];
                    } else {
                        $filename = $fileValues['path'];
                    }

                    $filename = $locator->findResource($filename, true, true);

                    try {
                        $message->attachFromPath($filename);
                    } catch (\Exception $e) {
                        // Log any issues
                        $this->grav['log']->error($e->getMessage());
                    }
                }
            }
        }

        //fire event to apply optional signers
        $this->grav->fireEvent('onEmailMessage', new Event(['message' => $message, 'params' => $params, 'form' => $form]));

        // Send e-mail

         $status = $this->email->send($message);

         if ($status < 1) {
            $this->grav->fireEvent('onFormValidationError', new Event([
                'form' => $form,
                'message' => $this->sendFailureMessage($params),
            ]));
            $event->stopPropagation();
            return;
        }

        //fire event after eMail was sent
        $this->grav->fireEvent('onEmailSent', new Event(['message' => $message, 'params' => $params, 'form' => $form]));
    }

    /**
     * What to tell the visitor when a form's email could not be sent.
     *
     * The transport's own words used to go straight onto the page. They are
     * written for whoever configured the mail account, not for whoever filled in
     * the contact form, and they routinely name the mail server, the login being
     * used and exactly why it was refused — which is ugly and is more than an
     * anonymous visitor has any business reading. The detail goes to the Grav
     * log instead, where the site owner will actually look for it.
     *
     * What the visitor gets, in order: an `error_message` on the email action,
     * then the plugin's own `error_message` setting, then a translated default.
     * With Grav's debugger switched on the transport's text is appended anyway,
     * because at that point the person reading the form is the person
     * configuring it.
     *
     * @param  array  $params  the email action's parameters
     * @return string
     */
    protected function sendFailureMessage(array $params): string
    {
        $detail = trim((string) $this->email->getLastSendMessage());

        // The site owner's copy, with everything in it.
        $this->grav['log']->error('plugin-email: could not send the form email: ' . ($detail !== '' ? $detail : 'no reason reported by the transport'));

        $message = $params['error_message'] ?? $this->grav['config']->get('plugins.email.error_message');

        if (!is_string($message) || trim($message) === '') {
            $message = 'PLUGIN_EMAIL.FORM_SEND_FAILURE';
        }

        // Run it through the translator either way, so a site may configure a
        // literal sentence or a language key of its own and both work.
        $message = $this->grav['language']->translate($message);

        if ($detail !== '' && $this->grav['config']->get('system.debugger.enabled')) {
            $message .= ' (' . $detail . ')';
        }

        return $message;
    }

    /**
     * Used for dynamic blueprint field
     *
     * @return array
     */
    public static function getEngines(): array
    {
        $engines = (object) [
            'sendmail' => 'Sendmail',
            'smtp' => 'SMTP',
            'smtps' => 'SMTPS',
            'native' => 'Native',
            'none' => 'PLUGIN_ADMIN.DISABLED',
        ];
        Grav::instance()->fireEvent('onEmailEngines', new Event(['engines' => $engines]));
        return (array) $engines;
    }

    /**
     * @deprecated 4.0 Switched from Swiftmailer to Symfony/Mailer - No longer supported
     */
    public function onSchedulerInitialized(Event $e)
    {

    }

}
