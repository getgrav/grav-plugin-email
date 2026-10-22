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
        $debug = "> POST /client/v4/accounts/x/email/sending/send HTTP/2\nHost: api.cloudflare.com\nAuthorization: Bearer cfut_abcdefSECRET123\nContent-Type: application/json\n"
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

    /** A token with no scheme word in front, and the header after it, which stays. */
    public function testABareTokenIsRedactedAndTheNextHeaderSurvives(): void
    {
        $out = Email::redact("> Authorization: abcdefTOKENonly\n> X-Request-Id: 42\n");

        self::assertStringNotContainsString('abcdefTOKENonly', $out);
        self::assertStringContainsString('> X-Request-Id: 42', $out);
    }

    public function testAwsSignatureCredentialsAreRedacted(): void
    {
        $out = Email::redact("> Authorization: AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/20261021/us-east-1/ses/aws4_request, SignedHeaders=host, Signature=deadbeef\n> X-Amz-Security-Token: FQoGZXIvYXdzEXAMPLE\n");

        self::assertStringNotContainsString('AKIAEXAMPLE', $out);
        self::assertStringNotContainsString('deadbeef', $out);
        self::assertStringNotContainsString('FQoGZXIvYXdzEXAMPLE', $out);
    }

    /** Whatever the server's prompt says, the client's next lines after AUTH LOGIN are the credentials. */
    public function testSmtpLoginIsRedactedWhateverThePrompt(): void
    {
        $debug = "> AUTH LOGIN\n< 334 VXNlcm5hbWU6IA==\n> dXNlckBleGFtcGxlLmNvbQ==\n< 334 UGFzc3dvcmQ6IA==\n> c2VjcmV0cGFzc3dvcmQ=\n< 235 2.7.0 Authentication successful\n> MAIL FROM:<a@example.com>\n";

        $out = Email::redact($debug);

        self::assertStringNotContainsString('c2VjcmV0cGFzc3dvcmQ=', $out);
        self::assertStringContainsString('> MAIL FROM:<a@example.com>', $out, 'nothing after the exchange is touched');
    }

    public function testNothingIsNothing(): void
    {
        self::assertSame('', Email::redact(null));
        self::assertSame('< 250 2.0.0 Ok', Email::redact('< 250 2.0.0 Ok'));
    }
}
