<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * Builds a safe plain-text message and delivers it through mail.client:
 *  - 'smtp': your mail provider's server;
 *  - 'brevo': Brevo's HTTP API (port 443 only: for hosts that block
 *    outbound mail ports, e.g. Altervista).
 *
 * Header safety: the recipient, sender and subject never contain raw user
 * input. The only visitor-supplied values are Reply-To (validated as a bare
 * email address) and the sender NAME (encoded, see displayName()).
 */
final class MailService
{
    public function __construct(private readonly array $cfg)
    {
    }

    /**
     * @param list<array{name:string, mime:string, size:int, content:string}> $attachments
     *        already validated by Attachments::collect()
     */
    public function send(string $subject, string $body, string $replyTo, array $attachments = [], string $fromName = ''): void
    {
        $to = (string) $this->cfg['to'];
        $from = (string) $this->cfg['from'];
        foreach ([$to, $from, $replyTo] as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false || preg_match('/\s/', $addr) === 1) {
                throw new RuntimeException('Mail: invalid or missing address.');
            }
        }
        $fromName = self::cleanLine($fromName) ?: 'Contact form';
        $subject = self::cleanLine($subject);

        if ($this->cfg['client'] === 'smtp') {
            $this->viaSmtp($to, $from, $fromName, $replyTo, $subject, $body, $attachments);
        } else {
            $this->viaBrevo($to, $from, $fromName, $replyTo, $subject, $body, $attachments);
        }
    }

    private function viaBrevo(string $to, string $from, string $fromName, string $replyTo, string $subject, string $body, array $attachments): void
    {
        // JSON, not headers: Brevo encodes names and subject itself, and
        // json_encode makes injection impossible by construction.
        $payload = [
            'sender'      => ['email' => $from, 'name' => mb_substr($fromName, 0, 70)],
            'to'          => [['email' => $to]],
            'replyTo'     => ['email' => $replyTo],
            'subject'     => $subject,
            'textContent' => $body,
            'headers'     => ['Auto-Submitted' => 'auto-generated'],
        ];
        foreach ($attachments as $file) {
            $payload['attachment'][] = [
                'name'    => Attachments::headerName($file['name']),
                'content' => base64_encode($file['content']),
            ];
        }
        (new BrevoClient((array) $this->cfg['brevo']))->send($payload);
    }

    private function viaSmtp(string $to, string $from, string $fromName, string $replyTo, string $subject, string $body, array $attachments): void
    {
        $envelope = (string) ($this->cfg['envelope_from'] ?? '') ?: $from;
        if (filter_var($envelope, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Mail: invalid envelope_from.');
        }
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date'                      => date(DATE_RFC2822),
            'From'                      => self::displayName($fromName) . ' <' . $from . '>',
            'To'                        => $to,
            'Reply-To'                  => $replyTo,
            'Subject'                   => self::encodeHeader($subject),
            'Message-ID'                => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
            'Auto-Submitted'            => 'auto-generated',
            'X-Mailer'                  => 'MultiContactForm',
        ];

        $encodedBody = quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], "\r\n", $body));

        if ($attachments !== []) {
            // multipart/mixed: the text part first, then one base64 part per file.
            // File names in headers are reduced to safe ASCII (Attachments::headerName).
            $boundary = '=_contact_' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
            unset($headers['Content-Transfer-Encoding']);
            $mime = "This is a multi-part message in MIME format.\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                . $encodedBody . "\r\n";
            foreach ($attachments as $file) {
                $fname = Attachments::headerName($file['name']);
                if (preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $file['mime']) !== 1) {
                    throw new RuntimeException('Mail: invalid attachment type.');
                }
                $mime .= '--' . $boundary . "\r\n"
                    . 'Content-Type: ' . $file['mime'] . '; name="' . $fname . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $fname . "\"\r\n\r\n"
                    . chunk_split(base64_encode($file['content']), 76, "\r\n");
            }
            $encodedBody = $mime . '--' . $boundary . "--\r\n";
        }

        $raw = '';
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('Mail: header injection attempt blocked.');
            }
            $raw .= $name . ': ' . $value . "\r\n";
        }
        $smtp = $this->cfg['smtp'];
        $smtp['helo'] = $smtp['helo'] ?? $domain;
        (new SmtpClient($smtp))->send($envelope, [$to], $raw . "\r\n" . $encodedBody);
    }

    /** One line, no control characters. */
    private static function cleanLine(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text) ?? '');
    }

    /**
     * Display name for From: plain when it is only letters, digits and simple
     * punctuation; otherwise an RFC 2047 encoded-word, so a visitor's name can
     * never inject an address, a comma-separated list or a header.
     */
    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '');
        if ($name === '') {
            return 'Contact form';
        }
        if (preg_match("/^[A-Za-z0-9 .'_-]{1,70}$/", $name) === 1) {
            return $name;
        }
        return self::encodeWords($name);
    }

    /** RFC 2047 encoded-words (split on character boundaries), kept on a single line. */
    public static function encodeHeader(string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
        if (preg_match('/^[\x20-\x7e]*$/', $text) === 1 && strlen($text) <= 70) {
            return $text;
        }
        return self::encodeWords($text);
    }

    private static function encodeWords(string $text): string
    {
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text) as $char) {
            if (strlen(base64_encode($chunk . $char)) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode(" ", $words);
    }
}
