<?php

declare(strict_types=1);

use Mops\Core\App;
use Mops\Core\Config;
use Mops\Core\Env;
use Mops\Core\Paths;

if (!defined('MOPS_ROOT')) {
    define('MOPS_ROOT', dirname(__DIR__));
}

require MOPS_ROOT . '/vendor/autoload.php';

Env::load(Paths::make('.env'));

$config = Config::fromFile(Paths::make('config/config.php'));

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

// Required runtime folders (storage/ is never web-accessible).
foreach (['storage', 'uploads', 'cache', 'logs', 'backups'] as $folder) {
    Paths::ensure($config->path($folder));
}

$app = new App($config);

return $app->slim();
