<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Support;

/**
 * Starts `imap-server.php` as a child process with a script, and hands back
 * the port it listens on and, afterwards, the transcript of the conversation.
 */
final class ScriptedImapServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly int $port;

    private string $transcriptPath;

    private string $scriptPath;

    /**
     * @param list<array<string, mixed>> $steps
     * @param string                     $mode  plain, ssl or starttls
     */
    public function __construct(array $steps, string $mode = 'plain', ?string $cert = null)
    {
        $dir = sys_get_temp_dir();
        $this->scriptPath = tempnam($dir, 'imap-script-');
        $this->transcriptPath = tempnam($dir, 'imap-log-');
        file_put_contents($this->scriptPath, json_encode($steps, JSON_THROW_ON_ERROR));

        $command = [PHP_BINARY, __DIR__ . '/imap-server.php', $this->scriptPath, $this->transcriptPath, $mode];
        if ($cert !== null) {
            $command[] = $cert;
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the scripted IMAP server.');
        }
        $this->process = $process;

        stream_set_timeout($this->pipes[1], 10);
        $port = trim((string)fgets($this->pipes[1]));
        if (!ctype_digit($port)) {
            throw new \RuntimeException('The scripted IMAP server did not start: ' . stream_get_contents($this->pipes[2]));
        }
        $this->port = (int)$port;
    }

    /** The whole conversation, once the server has finished. */
    public function transcript(): string
    {
        $this->wait();

        return (string)file_get_contents($this->transcriptPath);
    }

    public function wait(): void
    {
        if (!\is_resource($this->process)) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $deadline = microtime(true) + 10;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->wait();
        @unlink($this->scriptPath);
        @unlink($this->transcriptPath);
    }

    /**
     * A self-signed certificate and key for 127.0.0.1 in one PEM file, for the
     * TLS tests. Null where the openssl extension is missing.
     */
    public static function selfSignedPem(): ?string
    {
        if (!\function_exists('openssl_pkey_new')) {
            return null;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        $path = tempnam(sys_get_temp_dir(), 'imap-cert-');
        file_put_contents($path, $certPem . $keyPem);

        return $path;
    }
}
