<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;

final class AuthController
{
    public function showLogin(Request $request, array $params): Response
    {
        // Sudah masuk? langsung ke beranda.
        if (Session::isStarted() || Session::hasCookie()) {
            Session::start($request);
            if (Session::userId() !== null) {
                return Response::redirect(url('/'));
            }
        }
        Session::start($request); // sesi tamu dibuat di sini agar formulir membawa token CSRF
        return View::response('auth/login', [
            'title' => 'Masuk — Peminjaman HME',
            'error' => Session::pullFlash('login_error'),
            'old'   => Session::pullFlash('login_old', ['username' => '', 'role' => 'staff']),
        ], 200, 'layout');
    }

    public function login(Request $request, array $params): Response
    {
        $username = $request->input('username', '');
        $username = is_string($username) ? $username : '';
        $role = (string) $request->input('role', 'staff');
        $role = in_array($role, ['admin', 'staff'], true) ? $role : 'staff';
        $password = $request->input('password', '');
        $password = is_string($password) ? $password : '';

        try {
            $user = AuthService::login($username, $password, $role, $request);
        } catch (HttpException $e) {
            if (!in_array($e->status(), [401, 429], true)) {
                throw $e;
            }
            Session::flash('login_error', $e->getMessage());
            Session::flash('login_old', ['username' => mb_substr(trim($username), 0, 50), 'role' => $role]);
            $res = Response::redirect(url('/login'), 303);
            return $e->status() === 429 ? $res->withHeader('Retry-After', $e->headers()['Retry-After'] ?? '60') : $res;
        }
        return Response::redirect($user['must_change_password'] ? url('/profile/password') : url('/'), 303);
    }

    public function logout(Request $request, array $params): Response
    {
        $u = $request->user();
        AuthService::logout($u ? (int) $u['id'] : null, $request);
        return Response::redirect(url('/login'), 303);
    }
}
