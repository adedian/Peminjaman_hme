<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

/**
 * Login, logout, dan ganti password.
 *
 * Keamanan login:
 *  - Pesan gagal SELALU sama (username tak ada / password salah / peran salah / akun nonaktif) → tidak membocorkan akun.
 *  - password_verify() selalu dijalankan (hash tiruan bila user tak ada) → waktu respons tidak membedakan.
 *  - Pembatasan percobaan (jendela geser 15 menit): 5 gagal per (username+IP), 20 per IP, 30 per username (serangan terdistribusi).
 *  - Login sukses memutar ID sesi + token CSRF (anti session-fixation).
 */
final class AuthService
{
    public const WINDOW_SECONDS = 900;
    public const LIMIT_USER_IP = 5;
    public const LIMIT_IP = 20;
    public const LIMIT_USER = 30;

    private const GENERIC_FAIL = 'Username atau password salah.';

    /**
     * Verifikasi kredensial. Tidak membuat sesi.
     *
     * @return array{id:int,username:string,name:string,initials:string,role:string,must_change_password:bool,auth_version:int}
     */
    public static function attempt(string $username, string $password, string $role, Request $request): array
    {
        $username = mb_strtolower(trim($username));
        $key = substr($username, 0, 50);
        $ip = substr($request->ip(), 0, 45);

        self::assertNotThrottled($key, $ip);

        $user = null;
        if ($username !== '' && mb_strlen($username) <= 50 && $password !== '' && strlen($password) <= 1024) {
            $user = Database::one(
                'SELECT id, username, password_hash, name, initials, role, is_active, must_change_password, auth_version FROM users WHERE username = ?',
                [$username]
            );
        }

        // Selalu verifikasi (hash tiruan bila user tidak ada) agar durasi sama.
        $verified = password_verify($password, $user['password_hash'] ?? self::dummyHash());
        $ok = $user !== null && $verified && (int) $user['is_active'] === 1 && $user['role'] === $role;

        if (!$ok) {
            Database::insert('login_attempts', ['username' => $key, 'ip' => $ip, 'success' => 0]);
            AuditService::log('auth.login_failed', null, 'user', null, null, ['username' => $key, 'role' => $role], $request);
            self::pruneOldAttempts();
            throw new HttpException(401, 'invalid_credentials', self::GENERIC_FAIL);
        }

        Database::transaction(function () use ($user, $key, $ip, $password): void {
            Database::run('DELETE FROM login_attempts WHERE username = ? AND success = 0', [$key]);
            Database::insert('login_attempts', ['username' => $key, 'ip' => $ip, 'success' => 1]);
            $upd = ['last_login_at' => date('Y-m-d H:i:s')];
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                $upd['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            Database::update('users', $upd, ['id' => $user['id']]);
        });

        return self::publicUser($user);
    }

    /** attempt() + membuat sesi + audit. @return array<string,mixed> */
    public static function login(string $username, string $password, string $role, Request $request): array
    {
        $user = self::attempt($username, $password, $role, $request);
        Session::login($user['id'], $user['auth_version']);
        AuditService::log('auth.login', $user['id'], 'user', $user['id'], null, ['role' => $user['role']], $request);
        return $user;
    }

    public static function logout(?int $userId, Request $request): void
    {
        if ($userId !== null) {
            AuditService::log('auth.logout', $userId, 'user', $userId, null, null, $request);
        }
        Session::logout();
    }

    /**
     * Ganti password milik SENDIRI (BR-17). Tidak ada parameter id → tidak mungkin mengganti milik orang lain.
     * Sesi lain milik user ini berakhir (auth_version naik); sesi ini tetap masuk dengan ID baru.
     */
    public static function changePassword(int $userId, string $current, string $new, string $confirm, Request $request): void
    {
        $user = Database::one('SELECT id, username, password_hash, auth_version FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if ($user === null) {
            throw new HttpException(401, 'unauthenticated', 'Silakan masuk terlebih dahulu.');
        }
        $key = 'c:' . substr(sha1((string) $user['username']), 0, 16);
        self::assertNotThrottled($key, null);

        if (!password_verify($current, (string) $user['password_hash'])) {
            Database::insert('login_attempts', ['username' => $key, 'ip' => substr($request->ip(), 0, 45), 'success' => 0]);
            AuditService::log('auth.password_change_failed', $userId, 'user', $userId, null, null, $request);
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['current' => 'Password saat ini tidak tepat.']);
        }
        $err = PasswordPolicy::check($new, (string) $user['username']);
        if ($err !== null) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['new' => $err]);
        }
        if ($new === $current) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['new' => 'Password baru harus berbeda dari password lama.']);
        }
        if ($new !== $confirm) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['confirm' => 'Konfirmasi password tidak cocok.']);
        }

        $version = Database::transaction(function () use ($userId, $new, $key): int {
            Database::run(
                'UPDATE users SET password_hash = ?, must_change_password = 0, auth_version = auth_version + 1 WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $userId]
            );
            Database::run('DELETE FROM login_attempts WHERE username = ?', [$key]);
            return (int) Database::value('SELECT auth_version FROM users WHERE id = ?', [$userId]);
        });

        Session::login($userId, $version); // ID sesi & token CSRF baru; sesi di perangkat lain gugur
        AuditService::log('auth.password_changed', $userId, 'user', $userId, null, null, $request);
    }

    /**
     * @param array<string,mixed> $user
     * @return array{id:int,username:string,name:string,initials:string,role:string,must_change_password:bool,auth_version:int}
     */
    private static function publicUser(array $user): array
    {
        return [
            'id'                   => (int) $user['id'],
            'username'             => (string) $user['username'],
            'name'                 => (string) $user['name'],
            'initials'             => (string) $user['initials'],
            'role'                 => (string) $user['role'],
            'must_change_password' => (int) $user['must_change_password'] === 1,
            'auth_version'         => (int) $user['auth_version'],
        ];
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash('dummy-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }

    /** Lempar 429 bila salah satu batas terlampaui. */
    private static function assertNotThrottled(string $key, ?string $ip): void
    {
        $checks = [];
        if ($ip !== null) {
            $checks[] = ['username = ? AND ip = ?', [$key, $ip], self::LIMIT_USER_IP];
            $checks[] = ['ip = ?', [$ip], self::LIMIT_IP];
        } else {
            $checks[] = ['username = ?', [$key], self::LIMIT_USER_IP];
        }
        $checks[] = ['username = ?', [$key], self::LIMIT_USER];

        $retry = 0;
        foreach ($checks as [$where, $params, $limit]) {
            $rows = Database::all(
                "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM login_attempts
                 WHERE {$where} AND success = 0 AND created_at > (NOW() - INTERVAL " . self::WINDOW_SECONDS . ' SECOND)
                 ORDER BY created_at DESC LIMIT ' . (int) $limit,
                $params
            );
            if (count($rows) >= $limit) {
                $oldest = (int) $rows[count($rows) - 1]['age'];
                $retry = max($retry, self::WINDOW_SECONDS - $oldest);
            }
        }
        if ($retry > 0) {
            $retry = max(1, $retry);
            $min = (int) ceil($retry / 60);
            throw new HttpException(
                429,
                'too_many_attempts',
                "Terlalu banyak percobaan. Coba lagi dalam {$min} menit.",
                [],
                ['Retry-After' => (string) $retry]
            );
        }
    }

    private static function pruneOldAttempts(): void
    {
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    }
}
