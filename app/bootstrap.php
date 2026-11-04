<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    exit('Aplikasi membutuhkan PHP 8.0 atau lebih baru.');
}

define('HME_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = HME_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(HME_ROOT . '/vendor/autoload.php')) {
    require HME_ROOT . '/vendor/autoload.php';
}

require HME_ROOT . '/app/Helpers/functions.php';
require HME_ROOT . '/app/Helpers/icons.php';

Env::load(HME_ROOT . '/.env');
Config::load();
date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Jakarta'));
ErrorHandler::register();
