<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Penjaga bila sebuah akun ditandai `must_change_password`. Sejak revisi V2 penanda itu TIDAK lagi diset oleh
 * aplikasi mana pun (login pertama, migrasi, reset admin) — password lama dipertahankan. Penjaga dipertahankan
 * sebagai pengaman bila penanda diset manual di database.
 * Dipasang pada semua route terautentikasi KECUALI ganti password, logout, dan profil.
 */
final class ForcePasswordChangeMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user['must_change_password'] === true) {
            if ($request->isApi()) {
                throw new HttpException(403, 'password_change_required', 'Anda harus mengganti password terlebih dahulu.');
            }
            return Response::redirect(url('/profile/password'));
        }
        return $next($request);
    }
}
