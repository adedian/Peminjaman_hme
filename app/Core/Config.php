<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    public static function load(?string $file = null): void
    {
        $file = $file ?? HME_ROOT . '/app/Config/app.php';
        /** @var array<string,mixed> $cfg */
        $cfg = require $file;
        self::$items = $cfg;
    }

    /** Akses bertitik: Config::get('db.host', 'localhost'). */
    public static function get(string $key, $default = null)
    {
        $node = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Ganti satu nilai saat runtime (dipakai tes untuk mengganti driver; tidak menulis ke berkas). @param mixed $value */
    public static function override(string $key, $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($part, $ref)) {
                $ref[$part] = null;
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false) && !self::isProduction();
    }
}
