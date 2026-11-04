<?php
declare(strict_types=1);

namespace App\Core;

/** Token CSRF per sesi (synchronizer token), dibandingkan dengan hash_equals. */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function regenerate(): void
    {
        Session::start();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function verify(?string $given): bool
    {
        Session::start();
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($expected) || $expected === '' || $given === null || $given === '') {
            return false;
        }
        return hash_equals($expected, $given);
    }
}
