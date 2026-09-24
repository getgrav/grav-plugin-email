<?php

/**
 * A one-connection IMAP server that replays a script, for ImapMailboxTest.
 *
 * Run as a child process by {@see \Grav\Plugin\Email\Tests\Support\ScriptedImapServer}:
 *
 *     php imap-server.php <script.json> <transcript.log> <mode> [cert.pem]
 *
 * `mode` is `plain`, `ssl` (TLS from the first byte) or `starttls` (the script
 * says when with a `starttls` step). It listens on a free port on 127.0.0.1,
 * prints that port on stdout, accepts one client and plays the steps:
 *
 * - `{"send": "line"}` or `{"send": ["line", …]}`: each line with CRLF added;
 *   `{tag}` becomes the tag of the last command received.
 * - `{"literal": ["prefix ", "bytes", ")"]}`: `prefix{n}` CRLF, the bytes, then
 *   the suffix and CRLF, which is how a server sends a message body.
 * - `{"expect": "regex"}`: read one command (answering `+` to any literal in it
 *   unless it was sent as LITERAL+) and match it, without the tag, against the
 *   regex. A mismatch is written to the transcript as `MISMATCH` and ends the
 *   session.
 * - `{"starttls": true}`: switch the socket to TLS.
 * - `{"close": true}`: hang up.
 * - `{"sleep": 1.5}`: wait, for testing the client's timeout.
 *
 * Every line in each direction goes into the transcript, `C:` and `S:`.
 */

[$script, $transcriptPath, $mode] = [$argv[1], $argv[2], $argv[3]];
$cert = $argv[4] ?? null;

$steps = json_decode((string)file_get_contents($script), true);
$log = fopen($transcriptPath, 'wb');

$context = stream_context_create(['ssl' => $cert ? [
    'local_cert' => $cert,
    'allow_self_signed' => true,
    'verify_peer' => false,
] : []]);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 10);
if ($client === false) {
    exit(0);
}
stream_set_timeout($client, 10);

$tls = static function () use ($client, $log): void {
    stream_set_blocking($client, true);
    $ok = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    fwrite($log, $ok ? "TLS: on\n" : "TLS: failed\n");
};

if ($mode === 'ssl') {
    $tls();
}

$tag = '*';
$send = static function (string $bytes) use ($client, $log): void {
    fwrite($client, $bytes);
    foreach (explode("\r\n", rtrim($bytes, "\r\n")) as $line) {
        fwrite($log, 'S: ' . $line . "\n");
    }
};

$readCommand = static function () use ($client, $log, &$send): ?string {
    $command = '';
    while (true) {
        $line = fgets($client);
        if ($line === false) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        if (preg_match('/\{(\d+)(\+?)\}$/', $line, $m)) {
            fwrite($log, 'C: ' . $line . "\n");
            if ($m[2] === '') {
                $send("+ Ready for literal data\r\n");
            }
            $bytes = '';
            while (strlen($bytes) < (int)$m[1]) {
                $chunk = fread($client, (int)$m[1] - strlen($bytes));
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $bytes .= $chunk;
            }
            fwrite($log, 'L: ' . $bytes . "\n");
            $command .= $line . $bytes;
            continue;
        }
        fwrite($log, 'C: ' . $line . "\n");

        return $command . $line;
    }
};

foreach ($steps as $step) {
    if (isset($step['send'])) {
        foreach ((array)$step['send'] as $line) {
            $send(str_replace('{tag}', $tag, $line) . "\r\n");
        }
    } elseif (isset($step['literal'])) {
        [$prefix, $bytes, $suffix] = $step['literal'];
        $send(str_replace('{tag}', $tag, $prefix) . '{' . strlen($bytes) . "}\r\n");
        fwrite($client, $bytes);
        fwrite($log, 'S: <literal ' . strlen($bytes) . " bytes>\n");
        $send($suffix . "\r\n");
    } elseif (isset($step['expect'])) {
        $command = $readCommand();
        if ($command === null) {
            fwrite($log, "MISMATCH: connection closed, expected {$step['expect']}\n");
            break;
        }
        $space = strpos($command, ' ');
        $tag = $space === false ? $command : substr($command, 0, $space);
        $rest = $space === false ? '' : substr($command, $space + 1);
        if (!preg_match('/' . $step['expect'] . '/s', $rest)) {
            fwrite($log, "MISMATCH: expected /{$step['expect']}/ got {$rest}\n");
            $send($tag . " BAD unexpected command\r\n");
            break;
        }
    } elseif (!empty($step['starttls'])) {
        $tls();
    } elseif (isset($step['sleep'])) {
        usleep((int)($step['sleep'] * 1_000_000));
    } elseif (!empty($step['close'])) {
        break;
    }
}

// Let the client finish reading before the socket goes.
usleep(100_000);
@fclose($client);
fwrite($log, "END\n");
fclose($log);
