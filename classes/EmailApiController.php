<?php

declare(strict_types=1);

namespace Grav\Plugin\Email;

use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Response\ErrorResponse;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Exceptions\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class EmailApiController extends AbstractApiController
{
    /**
     * POST /email/send - Send an ad-hoc email.
     *
     * Required body fields: to, subject, body
     * Optional: from, cc, bcc, reply_to, content_type (default: text/html)
     */
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['to', 'subject', 'body']);

        $email = $this->getEmailService();

        $params = [
            'to' => $body['to'],
            'subject' => $body['subject'],
            'body' => $body['body'],
            'content_type' => $body['content_type'] ?? 'text/html',
        ];

        // Use configured from address, allow override
        if (!empty($body['from'])) {
            $params['from'] = $body['from'];
        } else {
            $from = $this->config->get('plugins.email.from');
            $fromName = $this->config->get('plugins.email.from_name');
            $params['from'] = $fromName ? "{$fromName} <{$from}>" : $from;
        }

        foreach (['cc', 'bcc', 'reply_to'] as $field) {
            if (!empty($body[$field])) {
                $params[$field] = $body[$field];
            }
        }

        try {
            $message = $email->buildMessage($params, []);
            $sent = $email->send($message);
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Internal Server Error', 'Failed to send email: ' . $e->getMessage());
        }

        if ($sent < 1) {
            $error = $email->getLastSendMessage() ?? 'Unknown error';
            throw new ApiException(500, 'Internal Server Error', 'Email send failed: ' . $error);
        }

        return ApiResponse::create([
            'message' => 'Email sent successfully.',
            'to' => $body['to'],
            'subject' => $body['subject'],
        ]);
    }

    /**
     * POST /email/test - Send a test email to verify configuration.
     *
     * Optional body field: to (defaults to configured recipient)
     */
    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $body = $this->getRequestBody($request);
        $to = $body['to'] ?? $this->config->get('plugins.email.to');

        if (!$to) {
            throw new ValidationException(
                'No recipient specified. Pass "to" or configure a default in email plugin settings.'
            );
        }

        $email = $this->getEmailService();

        $from = $this->config->get('plugins.email.from');
        $fromName = $this->config->get('plugins.email.from_name');

        try {
            $message = $email->buildMessage([
                'to' => $to,
                'from' => $fromName ? "{$fromName} <{$from}>" : $from,
                'subject' => 'Grav API - Test Email',
                'body' => '<h1>Test Email</h1><p>This test email was sent via the Grav API at ' . date('c') . '.</p><p>If you are reading this, your email configuration is working correctly.</p>',
                'content_type' => 'text/html',
            ], []);

            $sent = $email->send($message);
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Internal Server Error', 'Test email failed: ' . $e->getMessage());
        }

        if ($sent < 1) {
            $error = $email->getLastSendMessage() ?? 'Unknown error';
            throw new ApiException(500, 'Internal Server Error', 'Test email failed: ' . $error);
        }

        return ApiResponse::create([
            'message' => 'Test email sent successfully.',
            'to' => $to,
        ]);
    }

    private function getEmailService(): Email
    {
        $email = $this->grav['Email'] ?? null;

        if (!$email || !Email::enabled()) {
            throw new ApiException(503, 'Service Unavailable', 'Email plugin is not enabled or configured.');
        }

        return $email;
    }
}
