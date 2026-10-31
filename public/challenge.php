<?php

/**
 * Issues a fresh proof-of-work challenge and refreshes the CSRF cookie, so a
 * long-open tab recovers without reloading. The form token (?ft=) keeps its
 * original issue time while it is still valid for the current CSRF token.
 */

declare(strict_types=1);

use MCF\Altcha;
use MCF\Config;
use MCF\RateLimiter;
use MCF\Security;

define('MCF_APP', true);

require dirname(__DIR__) . '/app/includes/bootstrap.php';

Security::redirectPageLoad();
Security::sendHeaders(false);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        json_response(405, ['ok' => false, 'error' => 'forbidden']);
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
        json_response(403, ['ok' => false, 'error' => 'forbidden']);
    }

    $retry = RateLimiter::hit('challenge', Security::clientKey(), (array) Config::get('rate_limit.challenge'));
    if ($retry > 0) {
        header('Retry-After: ' . $retry);
        json_response(429, ['ok' => false, 'error' => 'rate', 'retry_after' => $retry]);
    }

    $csrf = Security::csrfToken();
    $ft = $_GET['ft'] ?? null;
    $age = Security::formAge($ft, $csrf);
    $formToken = $age !== null ? (string) $ft : Security::formToken($csrf);

    json_response(200, ['ok' => true, 'csrf' => $csrf, 'form_token' => $formToken, 'challenge' => Altcha::create()]);
} catch (Throwable $e) {
    error_log('[mcf] challenge error: ' . $e->getMessage());
    json_response(500, ['ok' => false, 'error' => 'generic']);
}
