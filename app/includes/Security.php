<?php

declare(strict_types=1);

namespace MCF;

defined('MCF_APP') || exit;

/**
 * HTTP hardening, CSRF (cookie-based, no sessions), secrets and client identification.
 */
final class Security
{
    // ── Headers ────────────────────────────────────────────────────────────

    private static ?string $nonce = null;

    /**
     * Per-response CSP nonce. Only the ALTCHA widget uses it: its <script>
     * tag carries it, and the widget stamps it on the <style> it injects
     * (read from document.currentScript.nonce).
     */
    public static function cspNonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Strict CSP, deny by default. Our own markup has no inline script or
     * style at all; configuration reaches the page as a non-executable
     * application/json block. The two exceptions are the ALTCHA widget's.
     *
     * With Matomo configured (Config::matomo()), its origin alone is added to
     * script, image and connection sources: matomo.js, the tracking request,
     * the <noscript> pixel. Nothing else, and nothing at all without it.
     */
    public static function sendHeaders(bool $html = true): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), '
            . 'magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=(), browsing-topics=()');
        $matomo = Config::matomo();
        $origins = $matomo !== null ? ' ' . $matomo['origin'] : '';
        $csp = [
            "default-src 'none'",
            "script-src 'self'" . $origins,
            // The ALTCHA widget injects its stylesheet in a <style> element.
            "style-src 'self' 'nonce-" . self::cspNonce() . "'",
            "img-src 'self'" . $origins,
            "connect-src 'self'" . $origins,
            // The ALTCHA widget starts its proof-of-work workers from Blob URLs.
            "worker-src 'self' blob:",
            "form-action 'self'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "manifest-src 'self'",
        ];
        $csp[] = 'upgrade-insecure-requests'; // HTTPS only (bootstrap.php)
        header('Content-Security-Policy: ' . implode('; ', $csp));
        // No Strict-Transport-Security: it covers the whole host, so it is
        // the server's to set, not the form's (README.md, "HSTS").
        header('Cache-Control: no-store, max-age=0');
        if ($html) {
            header('Content-Type: text/html; charset=UTF-8');
        }
    }

    public static function isHttps(): bool
    {
        if (Config::get('force_https') === true) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return self::fromTrustedProxy()
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    // ── CSRF (double-submit cookie) ────────────────────────────────────────
    //
    // No server-side session: the token lives in an httponly cookie and is
    // echoed in the form body; the server only compares the two. A cross-site
    // attacker can read neither the page nor the cookie. The cookie is
    // rewritten on every page, so it expires after 4 hours of inactivity.

    /** Also the ceiling of limits.max_form_age (Config::validate()): the form token is bound to this cookie. */
    public const CSRF_LIFETIME = 14400;

    public static function csrfCookieName(): string
    {
        return '__Host-mcf_csrf';
    }

    /** The browser's token (created if missing or malformed), refreshed on every call. */
    public static function csrfToken(): string
    {
        $name = self::csrfCookieName();
        $token = (string) ($_COOKIE[$name] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_COOKIE[$name] = $token;
        }
        if (!headers_sent()) {
            setcookie($name, $token, [
                'expires'  => time() + self::CSRF_LIFETIME,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        return $token;
    }

    public static function checkCsrf(mixed $submitted): bool
    {
        $cookie = $_COOKIE[self::csrfCookieName()] ?? null;
        return is_string($submitted) && is_string($cookie)
            && preg_match('/^[0-9a-f]{64}$/', $cookie) === 1
            && hash_equals($cookie, $submitted);
    }

    // ── Form timing (replaces session state) ───────────────────────────────

    /**
     * "<issued_at>.<hmac>" bound to the CSRF token: proves when the form was
     * served, for the minimum-fill-time and maximum-age checks.
     */
    public static function formToken(string $csrf, ?int $issuedAt = null): string
    {
        $issuedAt ??= time();
        return $issuedAt . '.' . hash_hmac('sha256', $issuedAt . '|' . $csrf, self::key('form'));
    }

    /** Seconds since the form was served, or null if the token is invalid. */
    public static function formAge(mixed $token, string $csrf): ?int
    {
        if (!is_string($token) || preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return null;
        }
        $expected = hash_hmac('sha256', $m[1] . '|' . $csrf, self::key('form'));
        if (!hash_equals($expected, $m[2])) {
            return null;
        }
        $age = time() - (int) $m[1];
        return $age >= 0 && $age <= (int) Config::get('limits.max_form_age') ? $age : null;
    }

    /**
     * challenge.php and submit.php answer the form's own fetch() calls. A page
     * load (the address typed or bookmarked, or the form posted with
     * JavaScript off) goes to the form instead of a page of JSON. Fetch
     * Metadata, set by the browser and not by page scripts; either header is
     * enough, since browsers differ on edge cases such as prefetch. 303, so
     * a POST arrives as a GET.
     */
    public static function redirectPageLoad(): void
    {
        if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? null) === 'navigate'
            || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? null) === 'document') {
            header('Location: ' . url(), true, 303);
            exit;
        }
    }

    /**
     * Defence in depth on top of the CSRF token: the request must come from our
     * own origin. Browsers always send Origin on cross-origin and on POST fetch.
     *
     * "Our own" is this request's Host: nothing to configure,
     * so nothing to get wrong. A hostile page can make the visitor's browser
     * post here, but the browser sets Host to the real destination and Origin
     * to the hostile page, so they never match. Host and port are compared,
     * not the scheme: behind a proxy that terminates TLS the server may see
     * plain http while the page is https.
     */
    public static function isSameOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            $seen = strtolower(rtrim((string) $origin, '/'));
            $host = self::requestHost();
            $seenHost = (string) preg_replace('#^https?://#', '', $seen);
            if ($host !== null && $seenHost !== $seen && hash_equals($host, $seenHost)) {
                return true;
            }
            // In the log, where the admin looks. Printable and short.
            error_log('[mcf] submission refused: the page origin is "'
                . substr((string) preg_replace('/[^\x21-\x7e]/', '?', $seen), 0, 100)
                . '", this request is for "' . ($host ?? '(no valid Host)') . '"');
            return false;
        }
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        return $site === null || $site === 'same-origin';
    }

    /**
     * This request's Host (name and optional port, lowercase), or null if it
     * is not a well-formed host: never trusted as free text.
     */
    public static function requestHost(): ?string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $ok = preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?$/', $host);
        return $ok === 1 ? $host : null;
    }

    /** Absolute origin of this service (https://host), for canonical links; null without a valid Host. */
    public static function selfOrigin(): ?string
    {
        $host = self::requestHost();
        return $host !== null ? 'https://' . $host : null;
    }

    // ── Secrets ────────────────────────────────────────────────────────────

    /** Purpose-bound sub-key derived from the master secret. */
    public static function key(string $purpose): string
    {
        return hash_hmac('sha256', 'contact:' . $purpose, self::masterSecret(), true);
    }

    /** Validated by Config: always present, never generated. */
    private static function masterSecret(): string
    {
        return (string) Config::get('secret');
    }

    // ── Client identification ──────────────────────────────────────────────

    private static function fromTrustedProxy(): bool
    {
        return self::ipInList((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (array) Config::get('client_ip.trusted_proxies', []));
    }

    /**
     * Whether $ip is in $list: single addresses ('203.0.113.7') or ranges in
     * CIDR notation ('173.245.48.0/20', '2400:cb00::/32'), which is how CDNs
     * publish theirs. Compared as bytes, so each IPv6 spelling of an address
     * matches; an entry that is not an address or a range matches nothing.
     */
    public static function ipInList(string $ip, array $list): bool
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $parts = explode('/', trim($entry), 2);
            $net = filter_var($parts[0], FILTER_VALIDATE_IP) !== false ? inet_pton($parts[0]) : false;
            if ($net === false || strlen($net) !== strlen($bin)) {
                continue; // not an address, or the other IP version
            }
            $bits = strlen($net) * 8;
            if (isset($parts[1])) {
                if (preg_match('/^[0-9]{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $bits) {
                    continue;
                }
                $bits = (int) $parts[1];
            }
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $header = Config::get('client_ip.header');
        if (is_string($header) && $header !== '' && self::fromTrustedProxy() && !empty($_SERVER[$header])) {
            $trusted = (array) Config::get('client_ip.trusted_proxies', []);
            // Walk the chain from the right, skipping our own proxies.
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER[$header])));
            foreach ($chain as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !self::ipInList($candidate, $trusted)) {
                    $ip = $candidate;
                    break;
                }
            }
        }
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /**
     * Pseudonymous rate-limit key: keyed hash of the IP (IPv6 reduced to /64).
     * The raw address is never written to disk.
     */
    public static function clientKey(): string
    {
        $ip = self::clientIp();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $bin = inet_pton($ip);
            $ip = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return hash_hmac('sha256', $ip, self::key('ratelimit'));
    }
}
