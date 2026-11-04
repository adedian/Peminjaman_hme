<?php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::isDebug() ? '1' : '0');
        ini_set('log_errors', '1');
        $logDir = (string) Config::get('paths.logs');
        if (is_dir($logDir)) {
            ini_set('error_log', $logDir . '/php-error.log');
        }

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false; // dibungkam dengan @
            }
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning('Deprecated: ' . $str, ['file' => $file, 'line' => $line]);
                return true;
            }
            throw new ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::toResponse($e, null)->withSecurityHeaders()->send();
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
            }
        });
    }

    /**
     * Apakah kesalahan ini harus dijawab dengan JSON? Bila Request belum terbentuk
     * (mis. JSON rusak saat parsing), lihat langsung URI-nya.
     */
    private static function isApiContext(?Request $request): bool
    {
        if ($request !== null) {
            return $request->isApi();
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && strncmp($path, $base, strlen($base)) === 0) {
            $path = substr($path, strlen($base));
        }
        return strncmp(rawurldecode($path), '/api/', 5) === 0;
    }

    public static function toResponse(Throwable $e, ?Request $request): Response
    {
        $api = self::isApiContext($request);

        if ($e instanceof HttpException) {
            $status = $e->status();
            if ($status >= 500) {
                Logger::error('HTTP ' . $status . ': ' . $e->getMessage(), ['code' => $e->errorCode()]);
            }
            $res = $api
                ? Response::error($status, $e->errorCode(), $e->getMessage(), $e->fields())
                : self::page($status, $e->getMessage());
            foreach ($e->headers() as $k => $v) {
                $res = $res->withHeader($k, $v);
            }
            return $res;
        }

        // Kesalahan tak terduga: catat detail, tampilkan pesan umum.
        Logger::error('Exception: ' . get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => Config::isDebug() ? $e->getTraceAsString() : null,
            'path' => $request ? $request->path() : null,
        ]);
        $msg = 'Terjadi kesalahan pada server. Silakan coba lagi.';
        if (Config::isDebug()) {
            $msg = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        return $api
            ? Response::error(500, 'server_error', $msg)->withHeader('X-Request-Id', Logger::requestId())
            : self::page(500, $msg)->withHeader('X-Request-Id', Logger::requestId());
    }

    private static function page(int $status, string $message): Response
    {
        $titles = [
            400 => 'Permintaan tidak valid', 401 => 'Perlu masuk', 403 => 'Akses ditolak', 404 => 'Halaman tidak ditemukan',
            405 => 'Metode tidak diizinkan', 419 => 'Sesi berakhir', 422 => 'Data belum valid', 429 => 'Terlalu banyak percobaan',
            500 => 'Terjadi kesalahan', 503 => 'Layanan belum tersedia',
        ];
        try {
            return View::response('errors/error', [
                'status'  => $status,
                'title'   => $titles[$status] ?? 'Terjadi kesalahan',
                'message' => $message,
            ], $status, null);
        } catch (Throwable $e) {
            return Response::html('<!doctype html><meta charset="utf-8"><title>Kesalahan</title><p>Terjadi kesalahan (' . $status . ').</p>', $status);
        }
    }
}
