<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembaca file .env. Variabel yang sudah ada di environment proses (getenv)
 * SELALU menang atas isi file — dipakai oleh tes dan oleh hosting yang mengatur env sendiri.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            self::$vars[$key] = self::parseValue($value);
        }
    }

    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $q = $raw[0];
        if (($q === '"' || $q === "'") && strlen($raw) >= 2) {
            $end = strrpos($raw, $q);
            if ($end !== false && $end > 0) {
                return substr($raw, 1, $end - 1);
            }
        }
        // nilai tanpa kutip: buang komentar di akhir baris ("abc # catatan")
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }
        return rtrim($raw);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $proc = getenv($key);
        if ($proc !== false) {
            return $proc;
        }
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return ($v !== null && $v !== '' && is_numeric($v)) ? (int) $v : $default;
    }

    /** Hanya untuk tes. */
    public static function reset(): void
    {
        self::$vars = [];
    }
}
