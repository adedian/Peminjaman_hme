<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;

if (!function_exists('e')) {
    /** Escape output HTML (proteksi XSS). Wajib untuk setiap nilai dinamis di View. */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /** @return mixed */
    function config(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    /** URL internal dengan base path (mis. /Peminjaman_hme di XAMPP, kosong di hosting). */
    function url(string $path = '/'): string
    {
        $base = (string) Config::get('app.base_path', '');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file = HME_ROOT . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? '?v=' . filemtime($file) : '';
        return url('assets/' . ltrim($path, '/')) . $v;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string { return Csrf::token(); }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}
