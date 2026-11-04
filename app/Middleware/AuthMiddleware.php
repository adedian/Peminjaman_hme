<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Autentikasi untuk SETIAP endpoint yang dilindungi (halaman maupun API).
 * Status akun dibaca ulang dari database di setiap request, sehingga
 * penonaktifan akun atau perubahan role berlaku seketika — bukan menunggu sesi habis.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Pengunjung anonim (tanpa cookie sesi) tidak dibuatkan sesi sama sekali.
        $id = null;
        if (Session::isStarted() || Session::hasCookie()) {
            // Polling realtime tidak dihitung sebagai aktivitas: tab yang dibiarkan terbuka tetap kedaluwarsa sesuai idle_timeout.
            Session::start($request, $request->path() !== '/api/v1/sync');
            $id = Session::userId();
        }

        $user = null;
        if ($id !== null) {
            $user = Database::one(
                'SELECT id, username, name, initials, role, is_active, must_change_password, auth_version FROM users WHERE id = ?',
                [$id]
            );
            if ($user !== null && (int) $user['is_active'] !== 1) {
                $user = null;
            }
            // Password diganti/direset di perangkat lain → sesi lama tidak berlaku lagi.
            if ($user !== null && (int) $user['auth_version'] !== Session::authVersion()) {
                $user = null;
            }
            if ($user === null) {
                Session::logout(); // akun dihapus/dinonaktifkan → sesi tidak berlaku lagi
            }
        }

        if ($user === null) {
            if ($request->isApi()) {
                throw new HttpException(401, 'unauthenticated', 'Silakan masuk terlebih dahulu.');
            }
            return Response::redirect(url('/login'));
        }

        $user['id'] = (int) $user['id'];
        $user['must_change_password'] = (int) $user['must_change_password'] === 1;
        $request->setAttribute('user', $user);
        return $next($request);
    }
}
