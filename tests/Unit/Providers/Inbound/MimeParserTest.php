<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers\Inbound;

use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\MimeParser;
use PHPUnit\Framework\TestCase;

/**
 * Real-world mail, parsed field by field.
 *
 * The fixtures in `tests/fixtures/inbound/` are written the way the clients
 * write them — Gmail's quoted-printable with a gmail_quote block, Outlook's
 * multipart/mixed with a winmail.dat, Apple Mail's format=flowed text and a
 * multipart/related inline screenshot, Latin-1 that is really Windows-1252 —
 * with CRLF line endings, because that is what arrives.
 */
final class MimeParserTest extends TestCase
{
    public function testPlainText(): void
    {
        $m = $this->fixture('plain.eml');

        $this->assertSame('anna@example.net', $m->from->email);
        $this->assertSame('Anna Berg', $m->from->name);
        $this->assertSame('support@helpdesk.example.com', $m->to[0]->email);
        $this->assertSame('Support', $m->to[0]->name);
        $this->assertSame('Printer on the second floor is jammed', $m->subject);
        $this->assertSame('caf7a1b2c3d4e5@mail.example.net', $m->messageId);
        $this->assertNull($m->inReplyTo);
        $this->assertSame([], $m->references);
        $this->assertSame(gmmktime(9, 13, 58, 9, 23, 2026), $m->date);
        $this->assertSame("Hi,\n\nThe printer on the second floor has jammed again and shows error E-42.\nCould someone take a look before noon? Danke schön!\n\nAnna\n", $m->text);
        $this->assertNull($m->html);
        $this->assertSame([], $m->attachments);
        $this->assertSame('text/plain', $m->contentType);
        $this->assertFalse($m->isReport());

        // The envelope an MTA recorded: Return-Path and the topmost Delivered-To.
        $this->assertSame('anna@example.net', $m->envelopeFrom);
        $this->assertSame(['support+t8f2kq@helpdesk.example.com'], $m->envelopeTo);

        // Only the topmost Authentication-Results counts; the forged one below it does not.
        $this->assertSame(['dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass'], $m->auth);
        $this->assertSame(0.4, $m->spamScore);

        // Headers kept in order, unfolded, as [name, value].
        $this->assertSame(['Return-Path', '<anna@example.net>'], $m->headers[0]);
        $this->assertStringStartsWith('from mx.example.net (mx.example.net [203.0.113.10])', (string)$m->header('received'));
        $this->assertStringNotContainsString("\n", (string)$m->header('Received'));
        $this->assertCount(2, $m->headerAll('Authentication-Results'));
        $this->assertNull($m->header('X-Not-There'));
        $this->assertSame(file_get_contents($this->path('plain.eml')), $m->raw);
    }

    public function testHtmlOnly(): void
    {
        $m = $this->fixture('html-only.eml');

        $this->assertNull($m->text);
        $this->assertSame('Marketing Team', $m->from->name);
        $this->assertStringContainsString('<strong>#10042</strong> has shipped', (string)$m->html);
        $this->assertStringContainsString('Tracking: <a href="https://track.example.org/10042">', (string)$m->html);
        $this->assertStringContainsString('Café au lait', (string)$m->html);
        $this->assertSame('20260922220000.ga10042@shop.example.org', $m->messageId);
        $this->assertSame(gmmktime(22, 0, 0, 9, 22, 2026), $m->date);
        $this->assertNull($m->envelopeFrom);
        $this->assertSame([], $m->envelopeTo);
        $this->assertSame([], $m->auth);
    }

    public function testMultipartAlternative(): void
    {
        $m = $this->fixture('alternative.eml');

        $this->assertSame('Jürgen Müller', $m->from->name);
        $this->assertSame('Rückfrage zur Rechnung', $m->subject);
        $this->assertCount(2, $m->to);
        $this->assertSame('Ops, Team', $m->to[1]->name);
        $this->assertSame('ops@helpdesk.example.com', $m->to[1]->email);
        $this->assertSame([], $m->cc, 'an empty group names nobody');
        $this->assertSame("Hallo,\n\nich habe eine Frage zur Rechnung 2026-117. Der Betrag scheint doppelt berechnet worden zu sein.\n\nGrüße\nJürgen", $m->text);
        $this->assertSame('<div>Hallo,</div><div>ich habe eine Frage zur Rechnung 2026-117.</div><div>Grüße<br>Jürgen</div>', $m->html);
        $this->assertStringNotContainsString('Epilogue', (string)$m->text . $m->html);
        $this->assertStringNotContainsString('multi-part message', (string)$m->text);
    }

    public function testGmailReply(): void
    {
        $m = $this->fixture('gmail-reply.eml');

        $this->assertSame('cagmail0002+abc=def@mail.gmail.com', $m->messageId);
        $this->assertSame('hd.1001.bb22@helpdesk.example.com', $m->inReplyTo, 'lower-cased, no brackets');
        $this->assertSame([
            'hd.1001.aa11@helpdesk.example.com',
            'cagmail0001=x+yz@mail.gmail.com',
            'hd.1001.bb22@helpdesk.example.com',
        ], $m->references);
        $this->assertSame('Re: [#1001] Cannot log in to the portal', $m->subject);
        $this->assertSame('support+t9zz1@helpdesk.example.com', $m->to[0]->email);
        $this->assertSame('t9zz1', $m->to[0]->detail());
        $this->assertStringStartsWith("Thanks, that worked! I can log in now.\n\nOn Tue, 22 Sept 2026 at 17:05, Helpdesk <support@helpdesk.example.com> wrote:\n\n> Hi Sam,", (string)$m->text);
        $this->assertStringContainsString('> — The support team', (string)$m->text);
        $this->assertStringContainsString('class="gmail_quote"', (string)$m->html);
        // Two DKIM results, one neutral: any pass is a pass.
        $this->assertSame(['dkim' => 'pass', 'spf' => 'pass', 'dmarc' => 'pass'], $m->auth);
        // Envelope first (the topmost Delivered-To), then To.
        $this->assertSame(['support@helpdesk.example.com', 'support+t9zz1@helpdesk.example.com'], $m->recipients());
    }

    public function testOutlookReplyWithWinmailDat(): void
    {
        $m = $this->fixture('outlook-reply.eml');

        $this->assertSame('Morgan.Lee@contoso.example', $m->from->email);
        $this->assertSame('Lee, Morgan', $m->from->name);
        $this->assertSame('morgan.lee@contoso.example', $m->from->normalized());
        $this->assertSame('bao.nguyen@contoso.example', $m->cc[0]->email);
        $this->assertSame('Nguyen, Bao', $m->cc[0]->name);
        $this->assertSame('am9pr01mb1234abcd5678ef90@am9pr01mb1234.eurprd01.prod.outlook.com', $m->messageId);
        $this->assertSame('hd.1002.cc33@helpdesk.example.com', $m->inReplyTo);
        $this->assertStringStartsWith("Hi,\n\nThe new starter begins on Monday; please enable VPN for bao.nguyen.", (string)$m->text);
        $this->assertStringContainsString("-----Original Message-----\nFrom: Helpdesk <support@helpdesk.example.com> \nSent:", (string)$m->text);
        $this->assertStringContainsString('id="divRplyFwdMsg"', (string)$m->html);
        // Microsoft writes Authentication-Results without the server name first.
        $this->assertSame(['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass'], $m->auth);

        $this->assertCount(1, $m->attachments);
        $winmail = $m->attachments[0];
        $this->assertSame('winmail.dat', $winmail->filename);
        $this->assertSame('application/ms-tnef', $winmail->contentType);
        $this->assertSame(120, $winmail->size);
        $this->assertSame("\x78\x9f\x3e\x22", substr($winmail->bytes(), 0, 4));
        $this->assertFalse($winmail->inline);
        $this->assertNull($winmail->contentId);
        $this->assertSame('dat', $winmail->extension());
    }

    public function testAppleMailReplyWithInlineImage(): void
    {
        $m = $this->fixture('apple-inline-image.eml');

        // format=flowed; delsp=yes joined back into one line.
        $this->assertSame(
            "Here is a screenshot of the flicker. It happens every time the laptop wakes from sleep.\n\nSent from my iPhone\n\n"
            . "> On 22 Sep 2026, at 18:00, Helpdesk <support@helpdesk.example.com> wrote:\n>\n> Could you send a screenshot?\n",
            $m->text
        );
        $this->assertStringContainsString('<img src="cid:4F1D9C2E-8A3B-4C5D-9E6F-0A1B2C3D4E5F@home"', (string)$m->html);
        $this->assertStringContainsString('<blockquote type="cite">', (string)$m->html);
        $this->assertSame('5e3b7d2a-9f11-4c8b-a0d1-7e2c3b4a5f60@example.com', $m->messageId);

        $this->assertCount(1, $m->attachments);
        $image = $m->attachments[0];
        // RFC 2231 filename* wins over the encoded-word name.
        $this->assertSame('Bildschirmfoto 2026-09-23 um 10.15.00.png', $image->filename);
        $this->assertSame('image/png', $image->contentType);
        $this->assertTrue($image->inline);
        $this->assertSame('4F1D9C2E-8A3B-4C5D-9E6F-0A1B2C3D4E5F@home', $image->contentId);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($image->bytes(), 0, 8));
        $this->assertSame(73, $image->size);
    }

    public function testIso88591IsReadAsWindows1252(): void
    {
        $m = $this->fixture('iso-8859-1.eml');

        $this->assertSame('François Lévesque', $m->from->name);
        $this->assertSame('Problème de connexion au café', $m->subject);
        // 0x93 and 0x94 are not ISO-8859-1 at all; every mail client reads them as curly quotes.
        $this->assertSame("Bonjour,\n\nLe Wi-Fi du café ne fonctionne plus depuis ce matin. Le message dit “accès refusé”.\n\nFrançois\n", $m->text);
    }

    public function testWindows1252EightBit(): void
    {
        $m = $this->fixture('windows-1252.eml');

        $this->assertSame('Renée O’Brien', $m->from->name);
        $this->assertSame("Hello,\n\nThe invoice says €120 but we agreed €100 – could you check? It’s urgent.\n\nRenée\n", $m->text);
        $this->assertTrue(mb_check_encoding((string)$m->text, 'UTF-8'));
    }

    public function testEncodedWordsGroupsAndAttachmentNames(): void
    {
        $m = $this->fixture('encoded-words.eml');

        // Two B-encoded words with the space inside the second: the gap between words is dropped.
        $this->assertSame('山田 太郎', $m->from->name);
        // An emoji split into its own word, then a Q word with a leading space, then plain text.
        $this->assertSame('お問い合わせ😂 and more text', $m->subject);

        $this->assertSame(['jane@example.com', 'alpha@example.com', 'beta@example.com'], array_map(static fn ($a) => $a->email, $m->to));
        $this->assertSame('Doe, Jane (Sales)', $m->to[0]->name);
        $this->assertSame('Beta "B" Person', $m->to[2]->name);
        $this->assertSame('Zoë', $m->cc[0]->name);
        $this->assertSame('plain@example.com', $m->cc[1]->email);
        $this->assertSame('Plain Person', $m->cc[1]->name, 'an old-style comment is the name');
        $this->assertSame('replies@example.jp', $m->replyTo[0]->email);

        $this->assertSame("こんにちは。請求書を送ります。\n", $m->text);

        $names = array_map(static fn (InboundAttachment $a) => $a->filename, $m->attachments);
        $this->assertSame(['見積書.pdf', 'Quartalsbericht übersicht Q3.csv', 'passwd'], $names);
        $this->assertSame('%PDF-1.4', substr($m->attachments[0]->bytes(), 0, 8));
        $this->assertSame("quarter,total\nQ3,1200", $m->attachments[1]->bytes());
        $this->assertSame('text/csv', $m->attachments[1]->contentType);
    }

    public function testDeliveryStatusNotification(): void
    {
        $m = $this->fixture('dsn-bounce.eml');

        $this->assertTrue($m->isReport());
        $this->assertSame('multipart/report', $m->contentType);
        $this->assertSame('', $m->envelopeFrom, 'Return-Path: <> is the null sender');
        $this->assertSame('MAILER-DAEMON@mx.helpdesk.example.com', $m->from->email);
        $this->assertSame('auto-replied', $m->header('Auto-Submitted'));
        $this->assertStringContainsString('could not', (string)$m->text);
        $this->assertStringContainsString('User unknown', (string)$m->text);

        $this->assertSame(['delivery-status.txt', 'headers.txt'], array_map(static fn ($a) => $a->filename, $m->attachments));
        $this->assertSame('message/delivery-status', $m->attachments[0]->contentType);
        $this->assertStringContainsString('Status: 5.1.1', $m->attachments[0]->bytes());
        $this->assertStringContainsString('Message-ID: <hd.1004.ee55@helpdesk.example.com>', $m->attachments[1]->bytes());
    }

    public function testAutoReply(): void
    {
        $m = $this->fixture('auto-reply.eml');

        $this->assertSame('auto-replied', $m->header('auto-submitted'));
        $this->assertSame('yes', $m->header('X-Autoreply'));
        $this->assertSame('auto_reply', $m->header('Precedence'));
        $this->assertSame('hd.1005.ff66@helpdesk.example.com', $m->inReplyTo);
        $this->assertSame('t4abc', $m->to[0]->detail());
        $this->assertSame("I am out of the office until Monday 29 September with limited access to email.\n", $m->text);
    }

    public function testForwardedMessageIsAnAttachmentNotBody(): void
    {
        $m = $this->fixture('forwarded.eml');

        $this->assertSame("Please handle the message below.\n", $m->text);
        $this->assertNull($m->html);
        $this->assertStringNotContainsString('INNER-BODY-MARKER', (string)$m->text);

        $this->assertCount(1, $m->attachments);
        $forward = $m->attachments[0];
        $this->assertSame('Refund request.eml', $forward->filename);
        $this->assertSame('message/rfc822', $forward->contentType);
        $this->assertFalse($forward->inline, 'a forwarded message is never shown as part of the body');

        // The attachment is a whole message that parses on its own.
        $inner = (new MimeParser())->parse($forward->bytes());
        $this->assertSame('customer@example.net', $inner->from->email);
        $this->assertSame('inner-0001@example.net', $inner->messageId);
        $this->assertStringContainsString('INNER-BODY-MARKER', (string)$inner->text);
    }

    public function testFromMimeAppliesOverrides(): void
    {
        $m = InboundMessage::fromMime((string)file_get_contents($this->path('plain.eml')), 'cloudflare', [
            'envelopeTo' => ['support+abc@helpdesk.example.com'],
            'envelopeFrom' => 'bounce@example.net',
            'providerId' => 'pm-123',
            'auth' => ['spf' => 'fail'],
            'nonsense' => 'ignored',
            'spamScore' => 'not a float',
        ]);

        $this->assertSame('cloudflare', $m->receiver);
        $this->assertSame(['support+abc@helpdesk.example.com'], $m->envelopeTo);
        $this->assertSame('bounce@example.net', $m->envelopeFrom);
        $this->assertSame('pm-123', $m->providerId);
        $this->assertSame(['spf' => 'fail'], $m->auth);
        $this->assertSame(0.4, $m->spamScore, 'a wrongly typed override is ignored, not thrown');
        $this->assertSame('Printer on the second floor is jammed', $m->subject);
    }

    public function testNeverThrows(): void
    {
        $parser = new MimeParser();
        $inputs = [
            '',
            "\n\n",
            'not a message at all',
            "Subject: only headers",
            "Content-Type: multipart/mixed; boundary=\"x\"\n\nno boundary ever appears",
            "Content-Type: multipart/mixed\n\nno boundary parameter",
            "Content-Type: text/plain; charset=x-klingon\n\nqapla\xFF",
            "Content-Transfer-Encoding: base64\n\n!!!not base64???",
            "Subject: =?utf-8?B?broken?= =?unknown-charset?Q?x=FFy?=\n\nbody",
            random_bytes(2000),
            str_repeat("Content-Type: multipart/mixed; boundary=\"b\"\n\n--b\n", 60) . 'deep',
        ];

        foreach ($inputs as $input) {
            $m = $parser->parse($input);
            $this->assertInstanceOf(InboundMessage::class, $m);
            $this->assertSame($input, $m->raw);
            $this->assertTrue(mb_check_encoding($m->subject, 'UTF-8'));
            $this->assertTrue(mb_check_encoding((string)$m->text, 'UTF-8'));
        }

        $m = $parser->parse("Subject: only headers");
        $this->assertSame('only headers', $m->subject);
        $this->assertTrue($m->from->isEmpty());

        $m = $parser->parse("Content-Type: multipart/mixed; boundary=\"x\"\n\nno boundary ever appears");
        $this->assertSame('no boundary ever appears', $m->text);
    }

    public function testManyPartsAreCapped(): void
    {
        $body = "Content-Type: multipart/mixed; boundary=\"b\"\n\n";
        for ($i = 0; $i < 900; $i++) {
            $body .= "--b\nContent-Type: application/octet-stream\n\nx\n";
        }
        $body .= "--b--\n";

        $m = (new MimeParser())->parse($body);
        $this->assertLessThan(500, \count($m->attachments));
        $this->assertGreaterThan(400, \count($m->attachments));
    }

    public function testDecodeWordsJoinsASplitMultibyteCharacter(): void
    {
        // "é" is C3 A9; split across two B words it only decodes when joined first.
        $this->assertSame('café', MimeParser::decodeWords('=?UTF-8?B?Y2Fmww==?= =?UTF-8?B?qQ==?='));
        $this->assertSame('a b', MimeParser::decodeWords('=?utf-8?q?a?= b'));
        $this->assertSame('ab', MimeParser::decodeWords("=?utf-8?q?a?=\r\n =?utf-8?q?b?="));
        $this->assertSame('plain text', MimeParser::decodeWords('plain text'));
        $this->assertSame('Grüße', MimeParser::decodeWords('=?ISO-8859-1*de?Q?Gr=FC=DFe?='));
        $this->assertSame('déjà', MimeParser::toUtf8("d\xE9j\xE0", 'utf-8'), 'mislabelled Latin-1 is repaired');
    }

    private function fixture(string $name): InboundMessage
    {
        $raw = file_get_contents($this->path($name));
        $this->assertIsString($raw);

        return (new MimeParser())->parse($raw);
    }

    private function path(string $name): string
    {
        return \dirname(__DIR__, 3) . '/fixtures/inbound/' . $name;
    }
}
