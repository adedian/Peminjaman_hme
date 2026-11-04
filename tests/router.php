<?php
declare(strict_types=1);

/** Router untuk `php -S` (hanya tes): file statis dilayani langsung, sisanya ke front controller. */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && $path !== '/index.php' && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
