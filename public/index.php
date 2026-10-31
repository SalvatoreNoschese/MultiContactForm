<?php

declare(strict_types=1);

use MCF\Attachments;
use MCF\Config;
use MCF\I18n;
use MCF\Prefs;
use MCF\Projects;
use MCF\Security;

define('MCF_APP', true);

require dirname(__DIR__) . '/app/includes/bootstrap.php';

// ?lang= / ?theme= / ?forget_cookie=, and the language in the URL (/es/…).
$lang = Prefs::handleRequest('');
Security::sendHeaders();

try {
    $csrf = Security::csrfToken();
    $formToken = Security::formToken($csrf);

    $limits = (array) Config::get('limits');
    $att = Attachments::limits();

    $clientConfig = [
        'lang'        => $lang,
        'languages'   => I18n::names(),
        'fallback'    => I18n::FALLBACK,
        'strings'     => I18n::clientStrings(),
        'altcha'      => I18n::altchaStrings(),
        'projects'    => Projects::publicConfig(),
        'auto_return' => Config::get('auto_return'),
        'limits'      => [
            'name_min'    => $limits['name_min'],
            'name_max'    => $limits['name_max'],
            'email_max'   => $limits['email_max'],
            'message_min' => $limits['message_min'],
            'message_max' => $limits['message_max'],
        ],
        'attachments' => [
            'enabled'         => $att['enabled'],
            'max_files'       => $att['max_files'],
            'max_file_bytes'  => $att['max_file_bytes'],
            'max_total_bytes' => $att['max_total_bytes'],
            'extensions'      => array_keys($att['types']),
            'types'           => Attachments::extensionList($att['types']),
        ],
        'endpoints'   => ['challenge' => url('challenge.php'), 'submit' => url('submit.php')],
    ];
    // Safe inside <script type="application/json">: <, >, & and quotes are escaped.
    $configJson = json_encode(
        $clientConfig,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    $t = static fn (string $key, array $vars = []): string => e(I18n::t($lang, $key, $vars));
    require MCF_APP_DIR . '/views/form.php';
} catch (Throwable $e) {
    error_log('[mcf] index error: ' . $e->getMessage());
    http_response_code(500);
    try {
        $lang = I18n::fromAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
    } catch (Throwable) {
        $lang = 'en'; // even with broken language files the error page must render
    }
    require MCF_APP_DIR . '/views/error.php';
}
