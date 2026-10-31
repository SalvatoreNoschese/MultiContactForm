<?php

declare(strict_types=1);

use MCF\Config;
use MCF\I18n;
use MCF\Prefs;
use MCF\Security;

define('MCF_APP', true);

require dirname(__DIR__) . '/app/includes/bootstrap.php';

// ?lang= / ?theme= / ?forget_cookie=, and the language in the URL (/es/…).
$lang = Prefs::handleRequest('privacy.php');
Security::sendHeaders();

$privacy = (array) Config::get('privacy');
$t = static fn (string $key, array $vars = []): string => e(I18n::t($lang, $key, $vars));

require MCF_APP_DIR . '/views/privacy.php';
