<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Inbound\Imap;

/**
 * One IMAP mailbox, read the way an inbound-mail poller needs: find what is
 * new, download it without marking it read, then mark it (and optionally move
 * it) once the caller has stored it safely.
 *
 *     $mailbox = ImapMailbox::connect(ImapConfig::fromArray($settings));
 *     if ($mailbox->uidValidity() !== $storedValidity) {
 *         $lastUid = 0;   // UIDs from before mean nothing now
 *     }
 *     foreach ($mailbox->fetchNew($lastUid, 50, $maxBytes) as $item) {
 *         if (!$item->isTooLarge()) {
 *             store($item->raw);
 *         }
 *         $mailbox->markProcessed($item->uid, $processedFolder);
 *         $lastUid = $item->uid;   // advance after each, so a crash repeats at most one
 *     }
 *     $mailbox->close();
 *
 * About eight IMAP commands, over {@see ImapProtocol}: `LOGIN`, `CAPABILITY`,
 * `SELECT`, `UID SEARCH`, `UID FETCH … BODY.PEEK[]`, `UID STORE`, `UID MOVE`
 * (or `UID COPY`, `\Deleted` and an expunge where the server has no MOVE),
 * `LOGOUT`. No ext/imap.
 *
 * ## Which messages are "new"
 *
 * With a last UID of 0 — the first run, or after `UIDVALIDITY` changed — it
 * asks for `UNSEEN`, so connecting a mailbox with ten years of read mail in it
 * does not import ten years of mail. After that it asks for every UID above the
 * last one, seen or not, so a message somebody opened in their mail client
 * before the poller ran is still picked up.
 */
final class ImapMailbox
{
    private int $uidValidity = 0;

    private ?int $uidNext = null;

    private int $exists = 0;

    private function __construct(
        private readonly ImapProtocol $protocol,
        private readonly ImapConfig $config,
    ) {
    }

    /**
     * Connect, log in and select the configured mailbox.
     *
     * @throws ImapException `auth` for refused credentials, `network` for an
     *         unreachable host, TLS failure or timeout, `protocol` for anything
     *         else the server refused
     */
    public static function connect(ImapConfig $config): self
    {
        $protocol = ImapProtocol::open($config);
        $mailbox = new self($protocol, $config);

        try {
            $mailbox->authenticate();
            $mailbox->select($config->mailbox);
        } catch (\Throwable $e) {
            $protocol->disconnect();
            throw $e instanceof ImapException ? $e : ImapException::protocol($e->getMessage());
        }

        return $mailbox;
    }

    /** The mailbox's UIDVALIDITY. When it changes, every UID stored before it is void. */
    public function uidValidity(): int
    {
        return $this->uidValidity;
    }

    /** The UIDNEXT the server announced on SELECT, where it did. */
    public function uidNext(): ?int
    {
        return $this->uidNext;
    }

    /** How many messages the mailbox held when it was selected. */
    public function exists(): int
    {
        return $this->exists;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->protocol->capabilities();
    }

    /**
     * Messages above `$afterUid` (or unseen ones when it is 0), oldest first,
     * at most `$limit` of them, each downloaded with `BODY.PEEK[]` so nothing is
     * marked read until {@see markProcessed()}.
     *
     * A message over `$maxBytes` (when that is above 0) is not downloaded; it
     * is yielded with a null `raw` so the caller can log it and mark it.
     *
     * @return \Generator<int, ImapItem>
     *
     * @throws ImapException
     */
    public function fetchNew(int $afterUid, int $limit = 50, int $maxBytes = 0): \Generator
    {
        if ($limit <= 0) {
            return;
        }

        $uids = $afterUid > 0
            ? $this->search(['UID SEARCH UID ' . ($afterUid + 1) . ':*'])
            : $this->search(['UID SEARCH UNSEEN']);

        // `n:*` always includes the highest UID, even when that is below n.
        $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid > $afterUid));
        sort($uids);
        $uids = \array_slice($uids, 0, $limit);
        if ($uids === []) {
            return;
        }

        $sizes = [];
        $response = $this->protocol->command(['UID FETCH ' . implode(',', $uids) . ' (UID RFC822.SIZE)']);
        foreach ($response['untagged'] as $line) {
            $fetch = self::parseFetch($line);
            if ($fetch !== null && $fetch['uid'] !== null && $fetch['size'] !== null) {
                $sizes[$fetch['uid']] = $fetch['size'];
            }
        }

        foreach ($uids as $uid) {
            $size = $sizes[$uid] ?? null;
            if ($maxBytes > 0 && $size !== null && $size > $maxBytes) {
                yield new ImapItem($uid, null, $size);
                continue;
            }

            $raw = $this->fetchBody($uid);
            if ($raw === null) {
                // Expunged by another client between the search and the fetch.
                continue;
            }
            if ($maxBytes > 0 && \strlen($raw) > $maxBytes) {
                yield new ImapItem($uid, null, \strlen($raw));
                continue;
            }

            yield new ImapItem($uid, $raw, $size ?? \strlen($raw));
        }
    }

    /**
     * Mark a message `\Seen`, and move it to `$moveTo` when that is given.
     *
     * With the MOVE extension this is `UID MOVE`. Without it, `UID COPY`, then
     * `\Deleted`, then `UID EXPUNGE` of that one message where the server has
     * UIDPLUS, or a plain `EXPUNGE` where it does not (which also removes any
     * other message already flagged `\Deleted` in this mailbox — the same thing
     * every mail client's "compact" does). A missing folder is created once
     * when the server says `TRYCREATE`.
     *
     * @throws ImapException
     */
    public function markProcessed(int $uid, ?string $moveTo = null): void
    {
        $this->protocol->command(['UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)']);

        if ($moveTo === null || trim($moveTo) === '') {
            return;
        }

        $folder = self::mailboxName($moveTo);

        if ($this->protocol->hasCapability('MOVE')) {
            $this->withTryCreate(['UID MOVE ' . $uid, $folder], $folder);

            return;
        }

        $this->withTryCreate(['UID COPY ' . $uid, $folder], $folder);
        $this->protocol->command(['UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)']);
        if ($this->protocol->hasCapability('UIDPLUS')) {
            $this->protocol->command(['UID EXPUNGE ' . $uid]);
        } else {
            $this->protocol->command(['EXPUNGE']);
        }
    }

    /** Log out and close the connection. Safe to call twice; never throws. */
    public function close(): void
    {
        if (!$this->protocol->isOpen()) {
            return;
        }

        try {
            $this->protocol->tryCommand(['LOGOUT']);
        } catch (\Throwable) {
            // The connection is going away either way.
        }
        $this->protocol->disconnect();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function authenticate(): void
    {
        switch ($this->config->auth) {
            case ImapConfig::AUTH_LOGIN:
                $this->login();

                return;

            case ImapConfig::AUTH_XOAUTH2:
                // The seam for OAuth: `AUTHENTICATE XOAUTH2` with
                // base64("user={user}\x01auth=Bearer {token}\x01\x01"), sent
                // inline where the server has SASL-IR, the token in $config->password.
                throw ImapException::auth('XOAUTH2 sign-in is not supported by this version of the Email plugin yet.');

            default:
                throw ImapException::auth(sprintf('Unknown IMAP sign-in method "%s".', $this->config->auth));
        }
    }

    private function login(): void
    {
        if ($this->protocol->hasCapability('LOGINDISABLED')) {
            throw ImapException::auth(
                'The server does not allow a password login on this connection. Use implicit TLS (port 993) or STARTTLS.'
            );
        }
        if ($this->config->username === '' || $this->config->password === '') {
            throw ImapException::auth('No IMAP username or password is configured.');
        }

        $response = $this->protocol->tryCommand([
            'LOGIN',
            ImapProtocol::astring($this->config->username),
            ImapProtocol::astring($this->config->password),
        ]);

        if ($response['status'] !== 'OK') {
            $text = preg_replace('/^\S+\s*/', '', $response['text']) ?? '';
            throw ImapException::auth(sprintf(
                'The IMAP server refused the login for %s: %s',
                $this->config->username,
                trim($text) !== '' ? trim($text) : 'no reason given'
            ));
        }

        // Servers often change what they offer after login; learn it unless the OK said.
        if (!preg_match('/\[CAPABILITY /i', $response['text'])) {
            $this->protocol->refreshCapabilities();
        }
    }

    private function select(string $mailbox): void
    {
        $response = $this->protocol->command(['SELECT', self::mailboxName($mailbox)]);

        foreach ($response['untagged'] as $line) {
            $text = $line['text'];
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $text, $m)) {
                $this->uidValidity = (int)$m[1];
            } elseif (preg_match('/\[UIDNEXT (\d+)\]/i', $text, $m)) {
                $this->uidNext = (int)$m[1];
            } elseif (preg_match('/^\* (\d+) EXISTS\b/i', $text, $m)) {
                $this->exists = (int)$m[1];
            }
        }

        if ($this->uidValidity === 0) {
            throw ImapException::protocol(sprintf(
                'The server selected %s without giving a UIDVALIDITY, so new mail cannot be tracked safely.',
                $mailbox
            ));
        }
    }

    /**
     * @param list<string> $parts
     *
     * @return list<int>
     */
    private function search(array $parts): array
    {
        $response = $this->protocol->command($parts);
        $uids = [];
        foreach ($response['untagged'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line['text'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int)$n;
                    }
                }
            }
        }

        return array_values(array_unique($uids));
    }

    private function fetchBody(int $uid): ?string
    {
        $response = $this->protocol->command(['UID FETCH ' . $uid . ' (UID RFC822.SIZE BODY.PEEK[])']);
        foreach ($response['untagged'] as $line) {
            $fetch = self::parseFetch($line);
            if ($fetch === null || $fetch['body'] === null) {
                continue;
            }
            // A server may send an unrelated FETCH (flags from another client) in between.
            if ($fetch['uid'] !== null && $fetch['uid'] !== $uid) {
                continue;
            }

            return $fetch['body'];
        }

        return null;
    }

    /**
     * The UID, size and body of one untagged FETCH line, or null when the line
     * is not a FETCH.
     *
     * @param array{text: string, literals: list<string>} $line
     *
     * @return array{uid: ?int, size: ?int, body: ?string}|null
     */
    private static function parseFetch(array $line): ?array
    {
        $text = $line['text'];
        if (!preg_match('/^\* \d+ FETCH \(/i', $text)) {
            return null;
        }

        $uid = preg_match('/[( ]UID (\d+)/i', $text, $m) ? (int)$m[1] : null;
        $size = preg_match('/[( ]RFC822\.SIZE (\d+)/i', $text, $m) ? (int)$m[1] : null;

        $body = null;
        if (preg_match('/BODY\[\](?:<\d+>)?\s*\{\d+\+?\}/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $index = preg_match_all('/\{\d+\+?\}/', substr($text, 0, $m[0][1]));
            $body = $line['literals'][$index] ?? null;
        } elseif (preg_match('/BODY\[\](?:<\d+>)?\s*"((?:[^"\\\\]|\\\\.)*)"/i', $text, $m)) {
            $body = (string)preg_replace('/\\\\(.)/s', '$1', $m[1]);
        }

        return ['uid' => $uid, 'size' => $size, 'body' => $body];
    }

    /**
     * @param list<string|array{literal: string}> $parts
     */
    private function withTryCreate(array $parts, string $folder): void
    {
        $response = $this->protocol->tryCommand($parts);
        if ($response['status'] === 'OK') {
            return;
        }

        if (stripos($response['code'], 'TRYCREATE') === 0) {
            $this->protocol->command(['CREATE', $folder]);
            $this->protocol->command($parts);

            return;
        }

        throw ImapException::protocol(sprintf('The server refused to move the message: %s', trim($response['text'])));
    }

    /**
     * A mailbox name as an IMAP argument, in modified UTF-7 (RFC 3501 5.1.3)
     * when it has anything outside ASCII in it.
     */
    private static function mailboxName(string $name): string
    {
        if (strcasecmp($name, 'INBOX') === 0) {
            return 'INBOX';
        }
        if (preg_match('/[\x80-\xFF]/', $name) && \function_exists('mb_convert_encoding')) {
            $encoded = @mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
            if (\is_string($encoded) && $encoded !== '') {
                $name = $encoded;
            }
        }

        return '"' . addcslashes($name, '"\\') . '"';
    }
}
