<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;

final class AuthController
{
    public function login(Request $request, array $params): Response
    {
        $d = Validator::make($request->body(), [
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:1024',
            'role'     => 'required|in:admin,staff',
        ], ['username' => 'Username', 'password' => 'Password', 'role' => 'Peran'])->validate();

        $user = AuthService::login((string) $d['username'], (string) $d['password'], (string) $d['role'], $request);
        return Response::ok([
            'user'       => self::publicUser($user),
            'csrf_token' => Csrf::token(),               // token baru setelah login (sesi diputar)
            'redirect'   => $user['must_change_password'] ? url('/profile/password') : url('/'),
        ]);
    }

    public function logout(Request $request, array $params): Response
    {
        $u = $request->user();
        AuthService::logout($u ? (int) $u['id'] : null, $request);
        return Response::ok(['redirect' => url('/login')]);
    }

    public function me(Request $request, array $params): Response
    {
        return Response::ok(['user' => self::publicUser($request->user() ?? []), 'csrf_token' => Csrf::token()]);
    }

    public function changePassword(Request $request, array $params): Response
    {
        $d = Validator::make($request->body(), [
            'current' => 'required|string|max:1024',
            'new'     => 'required|string|max:1024',
            'confirm' => 'required|string|max:1024',
        ], ['current' => 'Password saat ini', 'new' => 'Password baru', 'confirm' => 'Konfirmasi password'])->validate();

        AuthService::changePassword((int) $request->user()['id'], (string) $d['current'], (string) $d['new'], (string) $d['confirm'], $request);
        return Response::ok(['csrf_token' => Csrf::token(), 'redirect' => url('/')]);
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    private static function publicUser(array $u): array
    {
        return [
            'id' => $u['id'] ?? null, 'username' => $u['username'] ?? null, 'name' => $u['name'] ?? null,
            'initials' => $u['initials'] ?? null, 'role' => $u['role'] ?? null,
            'must_change_password' => (bool) ($u['must_change_password'] ?? false),
        ];
    }
}
