<?php

declare(strict_types=1);

/**
 * MOPS front controller. The only PHP file that may be reachable from the web.
 * Everything else (app/, config/, storage/, themes/, plugins/, vendor/) lives
 * outside the document root.
 */

define('MOPS', true);
define('MOPS_ROOT', dirname(__DIR__));
define('MOPS_START', microtime(true));

require MOPS_ROOT . '/vendor/autoload.php';

$app = require MOPS_ROOT . '/app/bootstrap.php';

$app->run();
