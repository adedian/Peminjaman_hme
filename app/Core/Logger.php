<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Log berkas harian di storage/logs (satu baris JSON per kejadian).
 * Kunci yang berisi rahasia (password, token, ...) otomatis disamarkan.
 */
final class Logger
{
    private const REDACT = '/pass|pwd|secret|token|csrf|authorization|cookie|api[_-]?key/i';
    private static ?string $requestId = null;

    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(6));
    }

    /** @param array<string,mixed> $ctx */
    public static function error(string $msg, array $ctx = []): void   { self::write('ERROR', $msg, $ctx); }
    /** @param array<string,mixed> $ctx */
    public static function warning(string $msg, array $ctx = []): void { self::write('WARNING', $msg, $ctx); }
    /** @param array<string,mixed> $ctx */
    public static function info(string $msg, array $ctx = []): void    { self::write('INFO', $msg, $ctx); }

    /** @param array<string,mixed> $ctx */
    private static function write(string $level, string $msg, array $ctx): void
    {
        $dir = (string) (Config::get('paths.logs') ?? (HME_ROOT . '/storage/logs'));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // logging tidak boleh menjatuhkan aplikasi
        }
        $line = json_encode([
            't'   => date('c'),
            'lvl' => $level,
            'rid' => self::requestId(),
            'msg' => $msg,
            'ctx' => self::redact($ctx),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::REDACT, $k)) {
                $data[$k] = '[disamarkan]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }
}
