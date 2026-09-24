<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Inbound\Imap;

use Grav\Plugin\Email\Inbound\Imap\ImapConfig;
use Grav\Plugin\Email\Inbound\Imap\ImapException;
use Grav\Plugin\Email\Inbound\Imap\ImapItem;
use Grav\Plugin\Email\Inbound\Imap\ImapMailbox;
use Grav\Plugin\Email\Tests\Support\ScriptedImapServer;
use PHPUnit\Framework\TestCase;

/**
 * The IMAP client against a scripted server in a child process.
 *
 * Each test writes the conversation it expects, including the things a real
 * server does that a naive client trips on: untagged EXISTS, EXPUNGE and FETCH
 * FLAGS lines in the middle of an answer, a message body sent as a literal that
 * itself contains lines that look like IMAP responses, `n:*` answering a UID
 * below n, a server without MOVE, a folder that has to be created first, and
 * NO and BAD answers at each step.
 */
final class ImapMailboxTest extends TestCase
{
    private const BODY_ONE = "From: a@example.com\r\nSubject: One\r\n\r\nA line that looks like IMAP:\r\n* 9 EXPUNGE\r\nG0009 OK fake)\r\n";
    private const BODY_TWO = "From: b@example.com\r\nSubject: Two\r\n\r\nSecond {12}\r\nbody\r\n";

    public function testFetchesNewMessagesMarksAndMovesThem(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1 MOVE UIDPLUS'), [
            ['expect' => '^UID SEARCH UID 11:\*$'],
            ['send' => ['* 4 EXISTS', '* SEARCH 11 12', '{tag} OK Search completed']],
            ['expect' => '^UID FETCH 11,12 \(UID RFC822\.SIZE\)$'],
            ['send' => [
                '* 2 FETCH (UID 11 RFC822.SIZE ' . \strlen(self::BODY_ONE) . ')',
                '* 3 FETCH (UID 12 RFC822.SIZE ' . \strlen(self::BODY_TWO) . ')',
                '{tag} OK Fetch completed',
            ]],
            ['expect' => '^UID FETCH 11 \(UID RFC822\.SIZE BODY\.PEEK\[\]\)$'],
            ['send' => ['* 1 FETCH (FLAGS (\\Seen) UID 3)']],
            ['literal' => ['* 2 FETCH (UID 11 RFC822.SIZE ' . \strlen(self::BODY_ONE) . ' BODY[] ', self::BODY_ONE, ')']],
            ['send' => ['{tag} OK Fetch completed']],
            ['expect' => '^UID STORE 11 \+FLAGS\.SILENT \(\\\\Seen\)$'],
            ['send' => ['{tag} OK Store completed']],
            ['expect' => '^UID MOVE 11 "Processed"$'],
            ['send' => ['* OK [COPYUID 1 11 1]', '* 2 EXPUNGE', '{tag} OK Move completed']],
            ['expect' => '^UID FETCH 12 \(UID RFC822\.SIZE BODY\.PEEK\[\]\)$'],
            ['literal' => ['* 2 FETCH (BODY[] ', self::BODY_TWO, ' UID 12)']],
            ['send' => ['{tag} OK Fetch completed']],
            ['expect' => '^UID STORE 12 \+FLAGS\.SILENT \(\\\\Seen\)$'],
            ['send' => ['{tag} OK Store completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $this->assertSame(1700000001, $mailbox->uidValidity());
        $this->assertSame(13, $mailbox->uidNext());
        $this->assertSame(3, $mailbox->exists());
        $this->assertContains('MOVE', $mailbox->capabilities());

        $items = [];
        foreach ($mailbox->fetchNew(10, 50) as $item) {
            $items[] = $item;
            $mailbox->markProcessed($item->uid, $item->uid === 11 ? 'Processed' : null);
        }
        $mailbox->close();
        $mailbox->close();

        $this->assertCount(2, $items);
        $this->assertInstanceOf(ImapItem::class, $items[0]);
        $this->assertSame(11, $items[0]->uid);
        $this->assertSame(self::BODY_ONE, $items[0]->raw);
        $this->assertSame(\strlen(self::BODY_ONE), $items[0]->size);
        $this->assertFalse($items[0]->isTooLarge());
        $this->assertSame(12, $items[1]->uid);
        $this->assertSame(self::BODY_TWO, $items[1]->raw);

        $this->assertCleanTranscript($server->transcript());
    }

    public function testFallsBackToCopyDeleteExpungeAndCreatesAMissingFolder(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID STORE 7 \+FLAGS\.SILENT \(\\\\Seen\)$'],
            ['send' => ['{tag} OK Store completed']],
            ['expect' => '^UID COPY 7 "Helpdesk\/Done"$'],
            ['send' => ['{tag} NO [TRYCREATE] Mailbox does not exist']],
            ['expect' => '^CREATE "Helpdesk\/Done"$'],
            ['send' => ['{tag} OK Create completed']],
            ['expect' => '^UID COPY 7 "Helpdesk\/Done"$'],
            ['send' => ['{tag} OK Copy completed']],
            ['expect' => '^UID STORE 7 \+FLAGS\.SILENT \(\\\\Deleted\)$'],
            ['send' => ['{tag} OK Store completed']],
            ['expect' => '^EXPUNGE$'],
            ['send' => ['* 1 EXPUNGE', '{tag} OK Expunge completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $mailbox->markProcessed(7, 'Helpdesk/Done');
        $mailbox->close();

        $this->assertCleanTranscript($server->transcript());
    }

    public function testUsesUidExpungeWhereTheServerHasUidplus(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1 UIDPLUS'), [
            ['expect' => '^UID STORE 7 '],
            ['send' => ['{tag} OK Store completed']],
            ['expect' => '^UID COPY 7 "Done"$'],
            ['send' => ['{tag} OK Copy completed']],
            ['expect' => '^UID STORE 7 \+FLAGS\.SILENT \(\\\\Deleted\)$'],
            ['send' => ['{tag} OK Store completed']],
            ['expect' => '^UID EXPUNGE 7$'],
            ['send' => ['* 1 EXPUNGE', '{tag} OK Expunge completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $mailbox->markProcessed(7, 'Done');
        $mailbox->close();

        $this->assertCleanTranscript($server->transcript());
    }

    public function testAFirstRunAsksForUnseenMail(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UNSEEN$'],
            ['send' => ['* SEARCH', '{tag} OK Search completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $this->assertSame([], iterator_to_array($mailbox->fetchNew(0, 50)));
        $mailbox->close();

        $this->assertCleanTranscript($server->transcript());
    }

    public function testTheHighestUidThatStarRangesAlwaysReturnIsIgnored(): void
    {
        // `UID SEARCH UID 13:*` on a mailbox whose highest UID is 12 answers 12.
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UID 13:\*$'],
            ['send' => ['* SEARCH 12', '{tag} OK Search completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $this->assertSame([], iterator_to_array($mailbox->fetchNew(12, 50)));
        $mailbox->close();

        $this->assertCleanTranscript($server->transcript());
    }

    public function testTheLimitTakesTheOldestFirst(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UNSEEN$'],
            ['send' => ['* SEARCH 30 21 25', '{tag} OK Search completed']],
            ['expect' => '^UID FETCH 21 \(UID RFC822\.SIZE\)$'],
            ['send' => ['* 1 FETCH (UID 21 RFC822.SIZE 5)', '{tag} OK Fetch completed']],
            ['expect' => '^UID FETCH 21 \(UID RFC822\.SIZE BODY\.PEEK\[\]\)$'],
            ['literal' => ['* 1 FETCH (UID 21 RFC822.SIZE 5 BODY[] ', "a\r\nb\r", ')']],
            ['send' => ['{tag} OK Fetch completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $items = iterator_to_array($mailbox->fetchNew(0, 1), false);
        $mailbox->close();

        $this->assertCount(1, $items);
        $this->assertSame(21, $items[0]->uid);
        $this->assertSame("a\r\nb\r", $items[0]->raw);
        $this->assertCleanTranscript($server->transcript());
    }

    public function testAMessageOverTheLimitIsNotDownloaded(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UID 6:\*$'],
            ['send' => ['* SEARCH 6', '{tag} OK Search completed']],
            ['expect' => '^UID FETCH 6 \(UID RFC822\.SIZE\)$'],
            ['send' => ['* 1 FETCH (UID 6 RFC822.SIZE 50000000)', '{tag} OK Fetch completed']],
            ['expect' => '^UID STORE 6 \+FLAGS\.SILENT \(\\\\Seen\)$'],
            ['send' => ['{tag} OK Store completed']],
        ], self::logout()));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        $items = [];
        foreach ($mailbox->fetchNew(5, 50, 10 * 1024 * 1024) as $item) {
            $items[] = $item;
            $mailbox->markProcessed($item->uid);
        }
        $mailbox->close();

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->isTooLarge());
        $this->assertNull($items[0]->raw);
        $this->assertSame(50000000, $items[0]->size);
        $transcript = $server->transcript();
        $this->assertStringNotContainsString('BODY.PEEK', $transcript);
        $this->assertCleanTranscript($transcript);
    }

    public function testAQuotedPasswordIsEscapedAndAnEightBitOneIsSentAsALiteral(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1] ready']],
            ['expect' => '^LOGIN "user@example\.com" \{7\}p\xC3\xA4"ss\\\\$'],
            ['send' => ['{tag} OK [CAPABILITY IMAP4rev1] Logged in']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => ['* OK [UIDVALIDITY 9] ok', '{tag} OK [READ-WRITE] Select completed']],
            ['expect' => '^LOGOUT$'],
            ['send' => ['* BYE bye', '{tag} OK Logout completed']],
        ]);

        $mailbox = ImapMailbox::connect($this->config($server->port, "p\u{e4}\"ss\\"));
        $mailbox->close();

        $transcript = $server->transcript();
        $this->assertStringContainsString('+ Ready for literal data', $transcript);
        $this->assertCleanTranscript($transcript);

        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1] ready']],
            ['expect' => '^LOGIN "user@example\.com" "se\\\\"cr\\\\\\\\et"$'],
            ['send' => ['{tag} OK [CAPABILITY IMAP4rev1] Logged in']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => ['* OK [UIDVALIDITY 9] ok', '{tag} OK Select completed']],
            ['expect' => '^LOGOUT$'],
            ['send' => ['{tag} OK Logout completed']],
        ]);

        ImapMailbox::connect($this->config($server->port, 'se"cr\\et'))->close();
        $this->assertCleanTranscript($server->transcript());
    }

    public function testLiteralPlusSkipsTheContinuation(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1 LITERAL+] ready']],
            ['expect' => '^LOGIN "u" \{3\+\}\xC3\xA9x$'],
            ['send' => ['{tag} OK [CAPABILITY IMAP4rev1 LITERAL+] Logged in']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => ['* OK [UIDVALIDITY 9] ok', '{tag} OK Select completed']],
            ['expect' => '^LOGOUT$'],
            ['send' => ['{tag} OK Logout completed']],
        ]);

        ImapMailbox::connect($this->config($server->port, "\u{e9}x", 'u'))->close();
        $transcript = $server->transcript();
        $this->assertStringNotContainsString('+ Ready', $transcript);
        $this->assertCleanTranscript($transcript);
    }

    public function testRefusedCredentialsAreAnAuthErrorWithoutThePassword(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] ready']],
            ['expect' => '^LOGIN '],
            ['send' => ['{tag} NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)']],
        ]);

        try {
            ImapMailbox::connect($this->config($server->port, 'hunter2-secret'));
            $this->fail('A refused login must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isAuth());
            $this->assertSame(ImapException::AUTH, $e->kind);
            $this->assertStringContainsString('Invalid credentials', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
        $server->wait();
    }

    public function testLoginDisabledIsAnAuthErrorAndNoPasswordIsSent(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1 LOGINDISABLED] ready']],
            ['expect' => '^LOGOUT$'],
        ]);

        try {
            ImapMailbox::connect($this->config($server->port));
            $this->fail('LOGINDISABLED must stop the login.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isAuth());
        }
        $this->assertStringNotContainsString('LOGIN "', $server->transcript());
    }

    public function testXoauth2IsReservedButNotYetSupported(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1 AUTH=XOAUTH2 SASL-IR] ready']],
            ['close' => true],
        ]);

        $config = new ImapConfig('127.0.0.1', 'u', 'token', $server->port, ImapConfig::NONE, auth: ImapConfig::AUTH_XOAUTH2, timeout: 5);
        try {
            ImapMailbox::connect($config);
            $this->fail('XOAUTH2 is not implemented yet.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isAuth());
            $this->assertStringContainsString('XOAUTH2', $e->getMessage());
        }
        $server->wait();
    }

    public function testABadSelectIsAProtocolError(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1] ready']],
            ['expect' => '^LOGIN '],
            ['send' => ['{tag} OK [CAPABILITY IMAP4rev1] Logged in']],
            ['expect' => '^SELECT "Support"$'],
            ['send' => ['{tag} NO [NONEXISTENT] Unknown Mailbox: Support']],
        ]);

        try {
            ImapMailbox::connect($this->config($server->port, mailbox: 'Support'));
            $this->fail('A refused SELECT must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isProtocol());
            $this->assertStringContainsString('SELECT', $e->getMessage());
            $this->assertStringContainsString('Unknown Mailbox', $e->getMessage());
        }
        $server->wait();
    }

    public function testASelectWithoutUidvalidityIsRefused(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1] ready']],
            ['expect' => '^LOGIN '],
            ['send' => ['{tag} OK Logged in']],
            ['expect' => '^CAPABILITY$'],
            ['send' => ['* CAPABILITY IMAP4rev1 IDLE', '{tag} OK Capability completed']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => ['* 3 EXISTS', '{tag} OK Select completed']],
        ]);

        try {
            ImapMailbox::connect($this->config($server->port));
            $this->fail('No UIDVALIDITY must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isProtocol());
            $this->assertStringContainsString('UIDVALIDITY', $e->getMessage());
        }
        $this->assertCleanTranscript($server->transcript());
    }

    public function testABadCommandResponseIsAProtocolError(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UNSEEN$'],
            ['send' => ['{tag} BAD Command Argument Error. 11']],
        ]));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        try {
            iterator_to_array($mailbox->fetchNew(0, 10));
            $this->fail('BAD must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isProtocol());
        }
        $server->wait();
    }

    public function testAServerThatHangsUpMidFetchIsANetworkError(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UNSEEN$'],
            ['send' => ['* SEARCH 4', '{tag} OK Search completed']],
            ['expect' => '^UID FETCH 4 \(UID RFC822\.SIZE\)$'],
            ['send' => ['* 1 FETCH (UID 4 RFC822.SIZE 900)', '{tag} OK Fetch completed']],
            ['expect' => '^UID FETCH 4 '],
            ['send' => ['* 1 FETCH (UID 4 RFC822.SIZE 900 BODY[] {900}', 'only part of it']],
            ['close' => true],
        ]));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        try {
            iterator_to_array($mailbox->fetchNew(0, 10));
            $this->fail('A lost connection must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
        }
        $server->wait();
    }

    public function testAnUnexpectedByeIsANetworkError(): void
    {
        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), [
            ['expect' => '^UID SEARCH UNSEEN$'],
            ['send' => ['* BYE Autologout; idle for too long']],
        ]));

        $mailbox = ImapMailbox::connect($this->config($server->port));
        try {
            iterator_to_array($mailbox->fetchNew(0, 10));
            $this->fail('BYE must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
            $this->assertStringContainsString('Autologout', $e->getMessage());
        }
        $server->wait();
    }

    public function testASilentServerTimesOut(): void
    {
        $server = new ScriptedImapServer([
            ['sleep' => 2],
            ['send' => ['* OK too late']],
        ]);

        $started = microtime(true);
        try {
            ImapMailbox::connect(new ImapConfig('127.0.0.1', 'u', 'p', $server->port, ImapConfig::NONE, timeout: 0.5));
            $this->fail('A silent server must time out.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
            $this->assertStringContainsString('Timed out', $e->getMessage());
        }
        $this->assertLessThan(1.8, microtime(true) - $started);
        $server->wait();
    }

    public function testAnUnreachableHostIsANetworkError(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int)substr($name, strrpos($name, ':') + 1);

        try {
            ImapMailbox::connect(new ImapConfig('127.0.0.1', 'u', 'p', $port, ImapConfig::NONE, timeout: 2));
            $this->fail('A closed port must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
            $this->assertStringContainsString('Could not connect', $e->getMessage());
        }
    }

    public function testAGreetingByeIsANetworkError(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* BYE Too many connections from your IP']],
        ]);

        try {
            ImapMailbox::connect($this->config($server->port));
            $this->fail('A BYE greeting must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
        }
        $server->wait();
    }

    public function testImplicitTls(): void
    {
        $pem = ScriptedImapServer::selfSignedPem();
        if ($pem === null) {
            $this->markTestSkipped('The openssl extension is needed for the TLS tests.');
        }

        $server = new ScriptedImapServer(array_merge(self::loginAndSelect('IMAP4rev1'), self::logout()), 'ssl', $pem);
        $config = new ImapConfig('127.0.0.1', 'user@example.com', 'secret', $server->port, ImapConfig::SSL, timeout: 5, verifyPeer: false);
        $mailbox = ImapMailbox::connect($config);
        $this->assertSame(1700000001, $mailbox->uidValidity());
        $mailbox->close();

        $transcript = $server->transcript();
        $this->assertStringContainsString('TLS: on', $transcript);
        $this->assertCleanTranscript($transcript);
        @unlink($pem);
    }

    public function testImplicitTlsVerifiesTheCertificateByDefault(): void
    {
        $pem = ScriptedImapServer::selfSignedPem();
        if ($pem === null) {
            $this->markTestSkipped('The openssl extension is needed for the TLS tests.');
        }

        $server = new ScriptedImapServer([['send' => ['* OK ready']]], 'ssl', $pem);
        try {
            ImapMailbox::connect(new ImapConfig('127.0.0.1', 'u', 'p', $server->port, ImapConfig::SSL, timeout: 5));
            $this->fail('A self-signed certificate must be refused when verifying.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
            $this->assertStringContainsString('TLS', $e->getMessage());
        }
        $server->wait();
        @unlink($pem);
    }

    public function testStarttlsUpgradesBeforeLoginAndRelearnsCapabilities(): void
    {
        $pem = ScriptedImapServer::selfSignedPem();
        if ($pem === null) {
            $this->markTestSkipped('The openssl extension is needed for the TLS tests.');
        }

        $server = new ScriptedImapServer([
            ['send' => ['* OK Dovecot ready.']],
            ['expect' => '^CAPABILITY$'],
            ['send' => ['* CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED', '{tag} OK Pre-login capabilities listed']],
            ['expect' => '^STARTTLS$'],
            ['send' => ['{tag} OK Begin TLS negotiation now.']],
            ['starttls' => true],
            ['expect' => '^CAPABILITY$'],
            ['send' => ['* CAPABILITY IMAP4rev1 AUTH=PLAIN', '{tag} OK Capability completed']],
            ['expect' => '^LOGIN "user@example\.com" "secret"$'],
            ['send' => ['{tag} OK [CAPABILITY IMAP4rev1 MOVE] Logged in']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => ['* OK [UIDVALIDITY 42] UIDs valid', '{tag} OK Select completed']],
            ['expect' => '^LOGOUT$'],
            ['send' => ['* BYE Logging out', '{tag} OK Logout completed']],
        ], 'starttls', $pem);

        $config = new ImapConfig('127.0.0.1', 'user@example.com', 'secret', $server->port, ImapConfig::STARTTLS, timeout: 5, verifyPeer: false);
        $mailbox = ImapMailbox::connect($config);
        $this->assertSame(42, $mailbox->uidValidity());
        $this->assertContains('MOVE', $mailbox->capabilities());
        $mailbox->close();

        $this->assertCleanTranscript($server->transcript());
        @unlink($pem);
    }

    public function testStarttlsNeverFallsBackToPlainText(): void
    {
        $server = new ScriptedImapServer([
            ['send' => ['* OK [CAPABILITY IMAP4rev1] ready']],
        ]);

        try {
            ImapMailbox::connect(new ImapConfig('127.0.0.1', 'u', 'p', $server->port, ImapConfig::STARTTLS, timeout: 5));
            $this->fail('No STARTTLS must throw.');
        } catch (ImapException $e) {
            $this->assertTrue($e->isNetwork());
            $this->assertStringContainsString('STARTTLS', $e->getMessage());
        }
        $this->assertStringNotContainsString('LOGIN', $server->transcript());
    }

    public function testConfigFromArrayAndItsKeyNeverShowThePassword(): void
    {
        $config = ImapConfig::fromArray([
            'host' => 'imap.gmail.com',
            'encryption' => 'tls',
            'username' => 'Support@Example.com',
            'password' => 'app-password',
        ]);

        $this->assertSame(ImapConfig::STARTTLS, $config->encryption);
        $this->assertSame(143, $config->port);
        $this->assertSame('INBOX', $config->mailbox);
        $this->assertSame('support@example.com@imap.gmail.com:143/inbox', $config->key());
        $this->assertStringNotContainsString('app-password', print_r($config, true));

        $this->assertSame(993, ImapConfig::fromArray(['host' => 'h'])->port);
        $this->assertSame(ImapConfig::SSL, ImapConfig::fromArray(['host' => 'h'])->encryption);
    }

    public function testNoExtImapAnywhere(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4) . '/classes'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\bimap_[a-z_]+\s*\(/',
                (string)file_get_contents($file->getPathname()),
                $file->getPathname() . ' calls ext/imap'
            );
        }
    }

    private function config(int $port, string $password = 'secret', string $username = 'user@example.com', string $mailbox = 'INBOX'): ImapConfig
    {
        return new ImapConfig('127.0.0.1', $username, $password, $port, ImapConfig::NONE, $mailbox, 5.0);
    }

    /** @return list<array<string, mixed>> */
    private static function loginAndSelect(string $capabilities): array
    {
        return [
            ['send' => ['* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] Server ready']],
            ['expect' => '^LOGIN "user@example\.com" "secret"$'],
            ['send' => ['{tag} OK [CAPABILITY ' . $capabilities . '] Logged in']],
            ['expect' => '^SELECT INBOX$'],
            ['send' => [
                '* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)',
                '* 3 EXISTS',
                '* 0 RECENT',
                '* OK [UIDVALIDITY 1700000001] UIDs valid',
                '* OK [UIDNEXT 13] Predicted next UID',
                '{tag} OK [READ-WRITE] Select completed',
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function logout(): array
    {
        return [
            ['expect' => '^LOGOUT$'],
            ['send' => ['* BYE Logging out', '{tag} OK Logout completed']],
        ];
    }

    private function assertCleanTranscript(string $transcript): void
    {
        $this->assertStringNotContainsString('MISMATCH', $transcript, $transcript);
    }
}
