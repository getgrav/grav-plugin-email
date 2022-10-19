<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Common\Utils;
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
            'onAdminSave'               => ['onAdminSave', 0],
        ];
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
                    $this->sendFormEmail($form, $params, $vars);
                } else {
                    foreach ($params as $email) {
                        $this->sendFormEmail($form, $email, $vars);
                    }
                }

                break;
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
        $this->email->send($message);

        //fire event after eMail was sent
        $this->grav->fireEvent('onEmailSent', new Event(['message' => $message, 'params' => $params, 'form' => $form]));
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
