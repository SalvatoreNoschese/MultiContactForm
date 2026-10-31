<?php

declare(strict_types=1);

use MCF\ContactService;
use MCF\Security;

define('MCF_APP', true);

require dirname(__DIR__) . '/app/includes/bootstrap.php';

Security::redirectPageLoad();
Security::sendHeaders(false);

try {
    [$status, $payload] = ContactService::handle();
} catch (Throwable $e) {
    error_log('[mcf] submit error: ' . $e->getMessage());
    [$status, $payload] = [500, ['ok' => false, 'error' => 'generic']];
}

json_response($status, $payload);
