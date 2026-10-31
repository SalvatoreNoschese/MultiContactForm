<?php

declare(strict_types=1);

use MCF\Config;
use MCF\I18n;
use MCF\Prefs;
use MCF\Security;

defined('MCF_APP') || exit;

/**
 * The error pages: 403 and 404 (public/error.php) and the 503/500 of
 * bootstrap.php and index.php. Texts: the error.* keys of app/lang/<code>.php
 * (error.403.*, error.404.*); in English from here when the language files
 * themselves are what is broken, which only the 503/500 can meet, so only
 * the 403 and the 404 get the footer.
 *
 * @var string $lang
 * @var bool   $addressError  a 403 or a 404 (default false)
 */
$addressError ??= false;
$text = static function (string $key, string $fallback) use ($lang): string {
    try {
        return e(I18n::find($lang, $key) ?? $fallback);
    } catch (Throwable) {
        return e($fallback);
    }
};
$code = (int) http_response_code();
[$title, $heading, $message] = match (true) {
    $addressError && $code === 403 => [$text('error.403.title', 'Access denied'), $text('error.403.title', 'Access denied'), $text('error.403.text', '')],
    $addressError => [$text('error.404.title', 'Page not found'), $text('error.404.title', 'Page not found'), $text('error.404.text', '')],
    default => [$text('error.title', 'Service unavailable'), $text('error.heading', 'Service temporarily unavailable'), $text('error.text', 'Please try again in a few minutes.')],
};
?><!DOCTYPE html>
<html <?= $addressError ? Prefs::htmlAttributes($lang) : 'lang="' . e($lang) . '"' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
</head>
<body class="error-body">
<main class="error-card">
    <?php if ($addressError && (string) Config::get('site_name') !== ''): ?>
        <a class="error-brand" href="<?= e(Prefs::brandUrl($lang)) ?>">✉️ <?= e((string) Config::get('site_name')) ?></a>
    <?php endif; ?>
    <p class="error-code" aria-hidden="true"><?= $code ?></p>
    <h1 class="error-title"><?= $heading ?></h1>
    <p class="hint"><?= $message ?></p>
    <?php if ($addressError): ?>
        <p class="hint request-url"><?= e((Security::selfOrigin() ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')) ?></p>
        <a class="btn" href="<?= e(Prefs::pageUrl($lang, '')) ?>"><?= $text('privacy_back', 'Back to the contact form') ?></a>
    <?php endif; ?>
</main>
<?php if ($addressError) {
    $t = static fn (string $key, array $vars = []): string => e(I18n::t($lang, $key, $vars));
    require MCF_APP_DIR . '/views/footer.php';
} ?>
</body>
</html>
