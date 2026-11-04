<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Proteksi CSRF untuk semua method yang mengubah data (POST/PUT/PATCH/DELETE).
 * Token dibaca dari header X-CSRF-Token (AJAX) atau field _csrf (form).
 * Lapisan tambahan: bila ada header Origin, host-nya harus sama dengan host request.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->isSafeMethod()) {
            return $next($request);
        }
        // Tanpa sesi tidak mungkin ada token yang sah; tolak tanpa membuat file sesi baru.
        if (!Session::isStarted() && !Session::hasCookie()) {
            throw new HttpException(403, 'csrf_invalid', 'Sesi formulir tidak valid atau sudah berakhir. Muat ulang halaman lalu coba lagi.');
        }
        Session::start($request);

        $origin = $request->header('origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $port = parse_url($origin, PHP_URL_PORT);
            if ($port) {
                $originHost .= ':' . $port;
            }
            if ($originHost !== $request->host()) {
                throw new HttpException(403, 'csrf_origin', 'Permintaan ditolak karena asal tidak sesuai.');
            }
        }

        $token = $request->header('x-csrf-token');
        if ($token === null || $token === '') {
            $field = $request->input('_csrf');
            $token = is_string($field) ? $field : null;
        }
        if (!Csrf::verify($token)) {
            throw new HttpException(403, 'csrf_invalid', 'Sesi formulir tidak valid atau sudah berakhir. Muat ulang halaman lalu coba lagi.');
        }
        return $next($request);
    }
}
