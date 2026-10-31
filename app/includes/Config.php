<?php

declare(strict_types=1);

namespace MCF;

use RuntimeException;

defined('MCF_APP') || exit;

/**
 * Application settings: built-in defaults overridden by config.php.
 *
 * config.php (outside the web root, next to public/) is REQUIRED: without it,
 * or with an invalid secret or mailbox, the service refuses to run instead of
 * falling back to anything generated or guessed.
 */
final class Config
{
    private static ?array $settings = null;

    public static function defaults(): array
    {
        return [
            // Name shown in the top bar and in the page title (REQUIRED).
            'site_name' => '',

            // Where the name in the top bar links to (https); '' = the form itself.
            'site_url' => '',

            // Seconds before "Back to …" goes by itself after sending; 0 = only by hand.
            'auto_return' => 0,

            // Optional statistics: a self-hosted Matomo, cookieless (see
            // config-sample.php). Both empty = off, nothing loaded.
            'matomo' => [
                'url'     => '',
                'site_id' => '',
            ],

            // Time zone of the dates in the emails (any PHP time zone name).
            'timezone' => 'UTC',

            // false = auto-detect (HTTPS, or X-Forwarded-Proto from a trusted proxy);
            // true = always treat requests as HTTPS (proxy without a trusted header).
            'force_https' => false,

            // Writable directory (rate-limit counters, used challenges).
            // Outside public/ by construction.
            'data_path' => MCF_ROOT . '/_app-data',

            // HMAC master secret (REQUIRED, at least 32 characters).
            'secret' => '',

            'mail' => [
                'client'    => '',               // 'smtp' or 'brevo' (REQUIRED)
                'to'        => '',               // your mailbox: where messages arrive (REQUIRED)
                'from'      => '',               // sender address on your own domain (REQUIRED)
                // Sender NAME shown in your inbox; {name} = visitor, {project} = project
                // tag, {site} = site_name. The address stays 'from' (SPF/DKIM).
                'from_name' => '{name} via {project}',
                'envelope_from' => null,         // defaults to 'from'
                // Language of the notification YOU receive (any app/lang/<code>.php).
                // The visitor's own language is written inside the message.
                'language'  => 'en',
                // Used when client = 'brevo' (HTTPS API, port 443 only).
                'brevo' => [
                    'api_key'  => '',            // Brevo API v3 key ("xkeysib-…")
                    'timeout'  => 15,
                    'endpoint' => '',            // tests only; default: Brevo's API
                ],
                // Used when client = 'smtp'.
                'smtp' => [
                    'host'        => '',
                    'port'        => 587,
                    'encryption'  => 'tls',      // tls (STARTTLS) | ssl (implicit TLS) | none (credentials only to localhost)
                    'username'    => '',
                    'password'    => '',
                    'timeout'     => 15,
                    'verify_peer' => true,
                    'helo'        => null,       // defaults to the domain of 'from'
                ],
            ],

            'altcha' => [
                'level'       => 'high',          // PoW difficulty: low | medium | high
                'expires'     => 1200,            // challenge validity in seconds
                'show_logo'   => true,            // ALTCHA logo in the anti-spam box
                'show_footer' => true,            // "Protected by ALTCHA" link under it
            ],

            // Optional attachments (see app/includes/Attachments.php). php.ini limits
            // (upload_max_filesize, post_max_size, max_file_uploads) win if lower.
            'attachments' => [
                'enabled'         => true,
                'max_files'       => 3,
                'max_file_bytes'  => 4 * 1024 * 1024,   // per file
                'max_total_bytes' => 10 * 1024 * 1024,  // all files together
                // One list per mail client (the one of mail.client is used):
                // extension => the ONLY MIME type accepted for it (checked on the bytes).
                // Brevo accepts a fixed set of extensions (no webp).
                'types' => [
                    'smtp' => [
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png',
                        'webp' => 'image/webp',
                        'pdf'  => 'application/pdf',
                        'txt'  => 'text/plain',
                    ],
                    'brevo' => [
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png',
                        'pdf'  => 'application/pdf',
                        'txt'  => 'text/plain',
                    ],
                ],
            ],

            'limits' => [
                'post_max_bytes'   => 32768,
                'name_min'         => 2,
                'name_max'         => 100,
                'email_max'        => 254,
                'message_min'      => 20,
                'message_max'      => 5000,
                'min_fill_seconds' => 4,         // faster submissions are rejected
                'max_form_age'     => 7200,      // how long a form stays valid (≤ the CSRF cookie's 4 h)
            ],

            // Each rule: [max requests, window in seconds]. Windows ≤ 86400.
            'rate_limit' => [
                'attempt'       => [[30, 600]],               // per client: every request to submit.php
                'submit'        => [[5, 600], [20, 86400]],   // per client: messages (valid form, solved challenge)
                'submit_global' => [[60, 3600]],              // whole service
                'challenge'     => [[30, 600]],               // per client
            ],

            // Client IP detection for rate limiting. Only honour a forwarding
            // header when REMOTE_ADDR is one of the trusted proxies.
            'client_ip' => [
                'header'          => null,       // e.g. 'HTTP_CF_CONNECTING_IP' or 'HTTP_X_FORWARDED_FOR'
                'trusted_proxies' => [],         // your reverse proxies: IPs or CIDR ranges
            ],

            // Shown on the privacy page.
            'privacy' => [
                'controller'       => '',        // data controller's name (REQUIRED)
                'address'          => [],        // lines under the name, optional
                'contact_email'    => '',
                'project'          => '',        // projects.php key for privacy requests, optional
                'retention_months' => 24,
            ],
        ];
    }

    public static function all(): array
    {
        if (self::$settings === null) {
            $file = MCF_ROOT . '/config.php';
            if (!is_file($file)) {
                throw new ConfigException('config.php not found (copy config-sample.php).');
            }
            $override = require $file;
            if (!is_array($override)) {
                throw new ConfigException('config.php must return an array.');
            }
            self::$settings = self::validate(self::merge($override));
        }
        return self::$settings;
    }

    private static function merge(array $override): array
    {
        $settings = array_replace_recursive(self::defaults(), $override);
        // Rate-limit rules are lists: replace them wholesale.
        foreach ((array) ($override['rate_limit'] ?? []) as $bucket => $list) {
            $settings['rate_limit'][$bucket] = $list;
        }
        // The allowed types are whitelists: config.php replaces each list
        // (smtp, brevo) it sets, never extends it.
        foreach (['smtp', 'brevo'] as $client) {
            if (isset($override['attachments']['types'][$client]) && is_array($override['attachments']['types'][$client])) {
                $settings['attachments']['types'][$client] = $override['attachments']['types'][$client];
            }
        }
        return $settings;
    }

    /** Fails loudly on settings the service cannot run safely without. */
    private static function validate(array $s): array
    {
        $secret = (string) $s['secret'];
        if (strlen($secret) < 32 || str_contains(strtoupper($secret), 'CHANGE')) {
            throw new ConfigException('secret must be at least 32 random characters.');
        }
        foreach (['mail.to' => $s['mail']['to'], 'mail.from' => $s['mail']['from']] as $key => $addr) {
            if (filter_var((string) $addr, FILTER_VALIDATE_EMAIL) === false) {
                throw new ConfigException("$key must be a valid email address.");
            }
        }
        foreach (['force_https' => $s['force_https'],
                  'altcha.show_logo' => $s['altcha']['show_logo'], 'altcha.show_footer' => $s['altcha']['show_footer']] as $key => $flag) {
            if (!is_bool($flag)) {
                throw new ConfigException("$key must be true or false.");
            }
        }
        if (!in_array((string) $s['timezone'], timezone_identifiers_list(), true)) {
            throw new ConfigException("timezone must be a PHP time zone name, e.g. 'Europe/Rome'.");
        }
        date_default_timezone_set((string) $s['timezone']);
        if (!array_key_exists((string) $s['altcha']['level'], Altcha::LEVELS)) {
            throw new ConfigException("altcha.level must be 'low', 'medium' or 'high'.");
        }
        $m = $s['matomo'];
        if (!is_array($m) || !is_string($m['url'] ?? null) || !(is_string($m['site_id'] ?? null) || is_int($m['site_id'] ?? null))) {
            throw new ConfigException("matomo must be ['url' => '…', 'site_id' => '…'] (both '' to turn it off).");
        }
        if ($m['url'] !== '' || (string) $m['site_id'] !== '') {
            // The address of the Matomo instance, its trailing slash included:
            // matomo.js and matomo.php are appended to it.
            if (preg_match('#^https://[a-z0-9.-]+(:[0-9]{1,5})?/([A-Za-z0-9._~-]+/)*$#', $m['url']) !== 1) {
                throw new ConfigException("matomo.url must be your Matomo's https address ending in '/', e.g. https://stats.example.com/.");
            }
            if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $m['site_id']) !== 1) {
                throw new ConfigException('matomo.site_id must be the numeric ID of this site in Matomo (e.g. 4).');
            }
        }
        $siteUrl = (string) $s['site_url'];
        if ($siteUrl !== '' && (!str_starts_with($siteUrl, 'https://') || filter_var($siteUrl, FILTER_VALIDATE_URL) === false)) {
            throw new ConfigException("site_url must be an https URL (or '').");
        }
        if (!is_int($s['auto_return']) || $s['auto_return'] < 0 || $s['auto_return'] > 60) {
            throw new ConfigException('auto_return must be a number of seconds from 0 (off) to 60.');
        }
        // The form token is bound to the CSRF cookie, which expires after
        // Security::CSRF_LIFETIME of inactivity: a longer form age would be
        // cut short by the cookie without anyone being told.
        $minFill = $s['limits']['min_fill_seconds'];
        $maxAge = $s['limits']['max_form_age'];
        if (!is_int($minFill) || $minFill < 0) {
            throw new ConfigException('limits.min_fill_seconds must be a number of seconds, 0 or more.');
        }
        if (!is_int($maxAge) || $maxAge <= $minFill || $maxAge > Security::CSRF_LIFETIME) {
            throw new ConfigException('limits.max_form_age must be a number of seconds above limits.min_fill_seconds and at most '
                . Security::CSRF_LIFETIME . ' (the CSRF cookie lasts 4 hours).');
        }
        $client = $s['mail']['client'];
        if ($client !== 'smtp' && $client !== 'brevo') {
            throw new ConfigException("mail.client must be 'smtp' or 'brevo'.");
        }
        if ($client === 'smtp') {
            if ((string) $s['mail']['smtp']['host'] === '') {
                throw new ConfigException('mail.smtp.host is required with mail.client = smtp.');
            }
            if (str_contains(strtoupper((string) $s['mail']['smtp']['password']), 'CHANGE_ME')) {
                throw new ConfigException('mail.smtp.password is still the placeholder.');
            }
        }
        if ($client === 'brevo') {
            if (preg_match('/^[A-Za-z0-9_-]{20,200}$/', (string) $s['mail']['brevo']['api_key']) !== 1
                || str_contains(strtoupper((string) $s['mail']['brevo']['api_key']), 'CHANGE')) {
                throw new ConfigException('mail.brevo.api_key does not look like a Brevo API key.');
            }
            $endpoint = (string) $s['mail']['brevo']['endpoint'];
            if ($endpoint !== '' && preg_match('#^(https://|http://(127\.0\.0\.1|localhost)(:\d+)?/)#', $endpoint) !== 1) {
                throw new ConfigException('mail.brevo.endpoint must be https (http only on localhost, for tests).');
            }
        }
        if (!I18n::exists($s['mail']['language'])) {
            throw new ConfigException('mail.language must match a file in app/lang/.');
        }
        if (!empty($s['attachments']['enabled'])) {
            if (!class_exists('finfo')) {
                throw new ConfigException('attachments require the PHP fileinfo extension (or set attachments.enabled = false).');
            }
            foreach (['smtp', 'brevo'] as $list) {
                if (!is_array($s['attachments']['types'][$list] ?? null)) {
                    throw new ConfigException("attachments.types.$list must be a list of extension => MIME type.");
                }
                foreach ($s['attachments']['types'][$list] as $ext => $mime) {
                    if (preg_match('/^[a-z0-9]{1,10}$/', (string) $ext) !== 1 || preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string) $mime) !== 1) {
                        throw new ConfigException("attachments.types.$list: invalid entry '$ext'.");
                    }
                    // Brevo rejects the whole message for an extension it does not
                    // accept: refuse such a configuration instead of failing on send.
                    if ($list === 'brevo' && $client === 'brevo' && !in_array((string) $ext, Attachments::BREVO_EXTENSIONS, true)) {
                        throw new ConfigException("attachments.types.brevo: '$ext' is not accepted by Brevo.");
                    }
                }
            }
        }
        $contact = $s['privacy']['contact_email'] ?? '';
        if (!is_string($contact) || ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ConfigException("privacy.contact_email must be a valid email address (or '' to show the form instead).");
        }
        $address = $s['privacy']['address'];
        if (!is_array($address) || !array_is_list($address) || array_filter($address, static fn ($l): bool => !is_string($l)) !== []) {
            throw new ConfigException("privacy.address must be a list of lines, e.g. ['42046 Reggiolo (RE)'] (or []).");
        }
        $project = $s['privacy']['project'];
        if (!is_string($project) || ($project !== '' && preg_match('/^[a-z0-9_]{1,32}$/', $project) !== 1)) {
            throw new ConfigException("privacy.project must be a key of projects.php (e.g. 'contact') or ''.");
        }
        foreach (['site_name' => $s['site_name'], 'privacy.controller' => $s['privacy']['controller']] as $key => $value) {
            if (trim((string) $value) === '') {
                throw new ConfigException("$key is required.");
            }
        }
        return $s;
    }

    /**
     * Matomo when configured: its address, site ID, origin (for the CSP) and
     * host (for the privacy notice); null when off.
     *
     * @return array{url: string, site_id: string, origin: string, host: string}|null
     */
    public static function matomo(): ?array
    {
        $m = (array) self::get('matomo', []);
        $url = (string) ($m['url'] ?? '');
        if ($url === '') {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        return [
            'url'     => $url,
            'site_id' => (string) $m['site_id'],
            'origin'  => 'https://' . $host . ($port !== null && $port !== false ? ':' . $port : ''),
            'host'    => $host,
        ];
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /** For bin/lint.sh only: same merge and validation as config.php. */
    public static function override(array $settings): void
    {
        self::$settings = self::validate(self::merge($settings));
    }
}

final class ConfigException extends RuntimeException
{
}
