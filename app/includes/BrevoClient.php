<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * Minimal client for Brevo's transactional email API (HTTPS on port 443).
 *
 * The alternative to SMTP for hosts that block outbound mail ports (e.g.
 * Altervista): one JSON POST to /v3/smtp/email. cURL when available, PHP
 * streams otherwise; the certificate is always verified.
 */
final class BrevoClient
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly array $cfg)
    {
    }

    /** @param array<string, mixed> $payload Brevo "send transactional email" body */
    public function send(array $payload): void
    {
        $key = (string) ($this->cfg['api_key'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{20,200}$/', $key) !== 1) {
            throw new RuntimeException('Brevo: invalid API key format.');
        }
        $endpoint = (string) ($this->cfg['endpoint'] ?? '') ?: self::ENDPOINT;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'];
        $timeout = (int) ($this->cfg['timeout'] ?? 15);

        [$status, $response] = function_exists('curl_init')
            ? $this->viaCurl($endpoint, $headers, $json, $timeout)
            : $this->viaStream($endpoint, $headers, $json, $timeout);

        if ($status < 200 || $status >= 300) {
            // Brevo's error body holds a code and a message, never the email itself.
            $detail = substr(preg_replace('/\s+/', ' ', $response) ?? '', 0, 300);
            throw new RuntimeException("Brevo: HTTP $status $detail");
        }
    }

    /** @return array{0:int, 1:string} */
    private function viaCurl(string $url, array $headers, string $json, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            throw new RuntimeException('Brevo: connection failed (' . $error . ').');
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $response];
    }

    /** @return array{0:int, 1:string} */
    private function viaStream(string $url, array $headers, string $json, int $timeout): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Brevo: needs the curl extension or allow_url_fopen.');
        }
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", $headers),
                'content'         => $json,
                'timeout'         => $timeout,
                'ignore_errors'   => true, // read the error body too
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) !== 1) {
            throw new RuntimeException('Brevo: connection failed.');
        }
        return [(int) $m[1], $response];
    }
}
