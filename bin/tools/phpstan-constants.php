<?php

declare(strict_types=1);

// For PHPStan only (bin/tools/phpstan.neon): the constants the entry points in
// public/ and app/includes/bootstrap.php define.
define('MCF_APP', true);
define('MCF_ROOT', dirname(__DIR__, 2));
define('MCF_APP_DIR', MCF_ROOT . '/app');
define('MCF_VERSION', '0.0.0');
define('MCF_REPO', 'https://example.com/');
