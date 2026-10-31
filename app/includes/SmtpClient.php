<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * Minimal, dependency-free SMTP client.
 *
 * Supports implicit TLS (port 465), STARTTLS (port 587), AUTH PLAIN / LOGIN.
 * Certificates are verified by default. Credentials are never sent over an
 * unencrypted connection unless the server is on localhost.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;
    private array $capabilities = [];

    public function __construct(private readonly array $cfg)
    {
    }

    /**
     * @param string   $from       envelope sender
     * @param string[] $recipients envelope recipients
     * @param string   $data       full RFC 5322 message (headers + body), CRLF line endings
     */
    public function send(string $from, array $recipients, string $data): void
    {
        foreach (array_merge([$from], $recipients) as $addr) {
            if (preg_match('/^[^\s<>]+@[^\s<>]+$/', $addr) !== 1) {
                throw new RuntimeException('SMTP: invalid envelope address.');
            }
        }

        $this->connect();
        try {
            $this->authenticate();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            // Dot-stuffing (RFC 5321 §4.5.2) and terminating sequence.
            $body = preg_replace('/^\./m', '..', $data) ?? $data;
            $this->write(rtrim($body, "\r\n") . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221], false);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $enc = (string) $this->cfg['encryption'];
        $timeout = (int) ($this->cfg['timeout'] ?? 15);
        if ($host === '' || $port <= 0) {
            throw new RuntimeException('SMTP: host/port not configured.');
        }

        $verify = (bool) ($this->cfg['verify_peer'] ?? true);
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
        ]]);

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("SMTP: connection failed ($errno $errstr).");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);

        $this->expect([220]);
        $this->ehlo();

        if ($enc === 'tls') {
            if (!isset($this->capabilities['STARTTLS'])) {
                throw new RuntimeException('SMTP: server does not offer STARTTLS.');
            }
            $this->command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
                throw new RuntimeException('SMTP: TLS negotiation failed.');
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $helo = (string) ($this->cfg['helo'] ?? '');
        if ($helo === '' || preg_match('/^[A-Za-z0-9.-]{1,253}$/', $helo) !== 1) {
            $helo = 'localhost';
        }
        $lines = $this->command('EHLO ' . $helo, [250]);
        $this->capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', strtoupper(trim(substr($line, 4))), 2);
            $this->capabilities[$parts[0]] = $parts[1] ?? '';
        }
    }

    private function authenticate(): void
    {
        $user = (string) ($this->cfg['username'] ?? '');
        $pass = (string) ($this->cfg['password'] ?? '');
        if ($user === '') {
            return;
        }
        $secure = in_array($this->cfg['encryption'], ['ssl', 'tls'], true);
        $local = in_array($this->cfg['host'], ['localhost', '127.0.0.1', '::1'], true);
        if (!$secure && !$local) {
            throw new RuntimeException('SMTP: refusing to send credentials without TLS.');
        }

        $mechanisms = explode(' ', $this->capabilities['AUTH'] ?? '');
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], true, true);
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334], true, true);
            $this->command(base64_encode($pass), [235], true, true);
        } else {
            throw new RuntimeException('SMTP: no supported AUTH mechanism.');
        }
    }

    /** @return string[] response lines */
    private function command(string $cmd, array $expect, bool $strict = true, bool $secret = false): array
    {
        if (preg_match('/[\r\n]/', $cmd) === 1) {
            throw new RuntimeException('SMTP: illegal characters in command.');
        }
        $this->write($cmd . "\r\n");
        try {
            return $this->expect($expect);
        } catch (RuntimeException $e) {
            if ($strict) {
                throw $secret ? new RuntimeException('SMTP: authentication failed.') : $e;
            }
            return [];
        }
    }

    /** @return string[] */
    private function expect(array $codes): array
    {
        $lines = [];
        while (true) {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException('SMTP: ' . (!empty($meta['timed_out']) ? 'timeout' : 'connection closed') . '.');
            }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
            if (count($lines) > 100) {
                throw new RuntimeException('SMTP: response too long.');
            }
        }
        $code = (int) substr($lines[count($lines) - 1], 0, 3);
        if (!in_array($code, $codes, true)) {
            // Only the status line is logged; never message content.
            throw new RuntimeException('SMTP: unexpected reply ' . substr($lines[count($lines) - 1], 0, 120));
        }
        return $lines;
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($written = 0; $written < $len; $written += $n) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('SMTP: write failed.');
            }
        }
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }
}
