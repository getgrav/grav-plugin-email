<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit;

use Grav\Plugin\Email\Email;
use PHPUnit\Framework\TestCase;

/** Debug output reaches logs/email.log, so no credential may be in it. */
final class EmailRedactTest extends TestCase
{
    public function testHttpAuthHeadersAreRedacted(): void
    {
        $debug = "> POST /client/v4/accounts/x/email/sending/send HTTP/2 Host: api.cloudflare.com Authorization: Bearer cfut_abcdefSECRET123 Content-Type: application/json\n"
            . "> POST /v3/mail Authorization: Basic YXBpOmtleS1zZWNyZXQ=\n"
            . "> X-Postmark-Server-Token: 1234-abcd-SECRET\n"
            . "> api-key: xkeysib-SECRET\n";

        $out = Email::redact($debug);

        self::assertStringNotContainsString('SECRET', $out);
        self::assertStringNotContainsString('YXBpOmtleS1zZWNyZXQ=', $out);
        self::assertStringContainsString('Authorization: Bearer <redacted>', $out);
        self::assertStringContainsString('Host: api.cloudflare.com', $out, 'the rest of the line stays');
    }

    public function testTheSmtpAuthPasswordIsRedacted(): void
    {
        $debug = "> AUTH LOGIN\n< 334 VXNlcm5hbWU6\n> YXBpX3Rva2Vu\n< 334 UGFzc3dvcmQ6\n> c2VjcmV0LXRva2VuLXZhbHVl\n< 235 2.7.0 Authentication successful\n"
            . "> AUTH PLAIN AGFwaV90b2tlbgBzZWNyZXQ=\n";

        $out = Email::redact($debug);

        self::assertStringNotContainsString('c2VjcmV0LXRva2VuLXZhbHVl', $out);
        self::assertStringNotContainsString('AGFwaV90b2tlbgBzZWNyZXQ=', $out);
        self::assertStringContainsString('235 2.7.0 Authentication successful', $out);
    }

    public function testNothingIsNothing(): void
    {
        self::assertSame('', Email::redact(null));
        self::assertSame('< 250 2.0.0 Ok', Email::redact('< 250 2.0.0 Ok'));
    }
}
