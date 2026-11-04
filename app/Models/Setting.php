<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Pengaturan aplikasi (tabel settings). */
final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = Database::value('SELECT value FROM settings WHERE key_name = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v !== null && is_numeric($v)) ? (int) $v : $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, $value]
        );
    }
}
