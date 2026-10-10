<?php

declare(strict_types=1);

if (!defined('MOPS_ROOT')) {
    define('MOPS_ROOT', dirname(__DIR__));
}

define('MOPS', true);

require MOPS_ROOT . '/vendor/autoload.php';

date_default_timezone_set('UTC');

// Tests use a temporary SQLite file; the real storage/ database is never touched.
if (!defined('MOPS_TEST_ROOT')) {
    define('MOPS_TEST_ROOT', sys_get_temp_dir() . '/mops-tests-' . getmypid());
}

if (!is_dir(MOPS_TEST_ROOT)) {
    mkdir(MOPS_TEST_ROOT, 0775, true);
}
