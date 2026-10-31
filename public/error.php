<?php

declare(strict_types=1);

use MCF\Prefs;
use MCF\Security;

define('MCF_APP', true);

require dirname(__DIR__) . '/app/includes/bootstrap.php';

// What the server answers for an address it refuses or has no file for
// (ErrorDocument 403 and 404 in public/.htaccess), in the language of the
// address (/es/…) or else the visitor's. REDIRECT_STATUS, set by the server
// for that internal request and not by the client, says which; opened by
// its own address it is a 404. Nothing is redirected or stored here.
$lang = Prefs::pathLang() ?? Prefs::resolveLang();
http_response_code(($_SERVER['REDIRECT_STATUS'] ?? '') === '403' ? 403 : 404);
Security::sendHeaders();
$addressError = true;

require MCF_APP_DIR . '/views/error.php';
