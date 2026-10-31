<?php

declare(strict_types=1);

use MCF\Config;
use MCF\I18n;
use MCF\Projects;
use MCF\Security;
use MCF\Storage;

defined('MCF_APP') || exit;

// A friendly message instead of a parse error on an older PHP.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    http_response_code(500);
    exit('PHP 8.1 or later is required.');
}

// MCF_ROOT: the installation folder (config.php, projects.php, public/,
// _app-data/, created on the first request). MCF_APP_DIR: MCF_ROOT/app, the code no URL can reach
// (includes/, lang/, views/, vendor/); everything in app/ is reached from it.
define('MCF_ROOT', dirname(__DIR__, 2));
define('MCF_APP_DIR', dirname(__DIR__));
// MultiContactForm version, for whoever looks at the files: never shown on a
// page. CHANGELOG.md's latest heading must match (bin/lint.sh), and the
// release tag is vx.y.z of it.
define('MCF_VERSION', '1.0.0');
define('MCF_REPO', 'https://github.com/SalvatoreNoschese/MultiContactForm');

// Before anything is sent: redirects (Prefs) and the 503 below never reach
// Security::sendHeaders(), and nginx has no .htaccess to strip it.
header_remove('X-Powered-By');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC'); // until config.php sets 'timezone'

spl_autoload_register(static function (string $class): void {
    if (preg_match('/^MCF\\\\([A-Za-z][A-Za-z0-9]*)$/', $class, $m) === 1) {
        // MCF\Config is app/includes/Config.php.
        $file = __DIR__ . '/' . $m[1] . '.php';
    } elseif (preg_match('/^AltchaOrg\\\\Altcha\\\\([A-Za-z0-9\\\\]+)$/', $class, $m) === 1) {
        // The official ALTCHA library (PSR-4), fetched by bin/fetch-vendor.sh.
        $file = MCF_APP_DIR . '/vendor/altcha-lib-php/src/' . str_replace('\\', '/', $m[1]) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

/** Sends a JSON response and terminates. */
function json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** HTML escaping helper for views. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Icons and the link preview (Open Graph: Telegram, WhatsApp, social
 * networks). The preview needs absolute URLs, so without a known origin
 * only the icons are written.
 */
function head_tags(string $lang, string $page, string $title, string $description): string
{
    $out = '<link rel="icon" href="' . e(asset('favicon.ico')) . '" sizes="any">' . "\n"
        . '    <link rel="icon" href="' . e(asset('assets/img/favicon.svg')) . '" type="image/svg+xml">' . "\n"
        . '    <link rel="apple-touch-icon" href="' . e(asset('assets/img/apple-touch-icon.png')) . '">';
    $origin = Security::selfOrigin();
    if ($origin === null) {
        return $out . "\n";
    }
    $meta = [
        'og:type'         => 'website',
        'og:site_name'    => (string) Config::get('site_name'),
        'og:title'        => $title,
        'og:description'  => $description,
        'og:url'          => $origin . url($lang . '/' . $page),
        'og:image'        => $origin . asset('assets/img/og.png'),
        'og:image:width'  => '1200',
        'og:image:height' => '630',
    ];
    foreach ($meta as $property => $content) {
        $out .= "\n" . '    <meta property="' . $property . '" content="' . e($content) . '">';
    }
    return $out . "\n" . '    <meta name="twitter:card" content="summary_large_image">' . "\n";
}

/**
 * Matomo's tags when configured, '' otherwise: the loader is a file of ours
 * (the official snippet is an inline script, which the CSP forbids) and it
 * always runs cookieless. The <noscript> pixel counts visits
 * without JavaScript; Matomo takes the page from its Referer.
 */
function matomo_tags(): string
{
    $m = Config::matomo();
    if ($m === null) {
        return '';
    }
    return '<script src="' . e(asset('assets/js/matomo-loader.js')) . '" data-url="' . e($m['url'])
        . '" data-site-id="' . e($m['site_id']) . '" defer></script>' . "\n"
        . '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="' . e($m['url'] . 'matomo.php?idsite='
        . $m['site_id'] . '&rec=1') . '" alt="" width="1" height="1"></p></noscript>' . "\n";
}

/**
 * url() for a file in public/, with ?v= set to its modification time: the
 * browser keeps static files for a year (public/.htaccess, "immutable"), and
 * a new address on every change is what makes an update reach everyone at
 * once instead of after that year.
 */
function asset(string $path): string
{
    $mtime = @filemtime(MCF_ROOT . '/public/' . $path);
    return url($path) . ($mtime !== false ? '?v=' . $mtime : '');
}

/**
 * Root-relative URL of a path inside the service ("assets/css/style.css",
 * "it/privacy.php"), under its folder when it lives in one (/contact/…).
 * Pages sit under /<lang>/, so relative links would resolve there.
 *
 * The folder comes from the running script: nothing to configure. With the
 * fixed web root layout the root .htaccess maps every request into public/,
 * so the script runs as …/public/index.php while visitors see …/: that
 * "/public" is not part of the address.
 */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }
        $base = $dir === '.' ? '' : $dir;
    }
    return $base . '/' . ltrim($path, '/');
}

// Configuration is mandatory: no config.php (or an invalid one) means no
// service, and so does a data folder PHP cannot create or write. The reason
// goes to the server log only, never to the visitor.
if (PHP_SAPI !== 'cli') {
    try {
        Config::all();
        // HTTPS is mandatory, as in the other projects of this family: every
        // cookie is __Host- (accepted only over HTTPS) and the proof-of-work
        // needs a secure context. Checked after Config::all(), which knows
        // the trusted proxies and 'force_https'. The redirects in .htaccess
        // normally get there first; this catches what they cannot see (a
        // proxy whose X-Forwarded-Proto is not trusted, an nginx without the
        // redirect).
        if (!Security::isHttps()) {
            throw new RuntimeException('this request arrived over plain HTTP: serve the form over HTTPS; '
                . 'behind a TLS-terminating proxy, list it in client_ip.trusted_proxies or set force_https');
        }
        Projects::all(); // projects.php is mandatory too
        Storage::check(); // creates _app-data/ on the first request
    } catch (Throwable $e) {
        error_log('[mcf] configuration error: ' . $e->getMessage());
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 3600');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['submit.php', 'challenge.php'], true)) {
            header('Content-Type: application/json; charset=UTF-8');
            echo '{"ok":false,"error":"generic"}';
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        try {
            $lang = I18n::fromAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
        } catch (Throwable) {
            $lang = 'en'; // even with broken language files the error page must render
        }
        require MCF_APP_DIR . '/views/error.php';
        exit;
    }
}
