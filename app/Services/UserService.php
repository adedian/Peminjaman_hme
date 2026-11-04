<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use PDOException;

/**
 * Manajemen pengguna oleh admin (BR-15, BR-16, keputusan Q3 & Q7).
 * Tidak ada penghapusan permanen: akun dinonaktifkan agar riwayat transaksi tetap utuh.
 */
final class UserService
{
    private const COLS = 'id, username, name, initials, role, is_active, must_change_password, last_login_at, created_at';

    /** @return array<int,array<string,mixed>> */
    public static function listAll(): array
    {
        $rows = Database::all(
            'SELECT u.id, u.username, u.name, u.initials, u.role, u.is_active, u.must_change_password, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM loans l WHERE l.user_id = u.id AND l.status IN (\'pending\',\'approved\')) AS open_loans
             FROM users u ORDER BY u.is_active DESC, u.role ASC, u.name ASC'
        );
        return array_map([self::class, 'present'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $r = Database::one('SELECT ' . self::COLS . ' FROM users WHERE id = ?', [$id]);
        return $r === null ? null : self::present($r);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array{user:array<string,mixed>,temporary_password:?string}
     */
    public static function create(array $input, array $actor, Request $request): array
    {
        if (isset($input['username']) && is_string($input['username'])) {
            $input['username'] = mb_strtolower(trim($input['username']));
        }
        $data = Validator::make($input, [
            'username' => 'required|string|min:3|max:50|alpha_dash',
            'name'     => 'required|string|min:2|max:100',
            'role'     => 'required|in:admin,staff',
            'password' => 'nullable|string',
        ], ['username' => 'Username', 'name' => 'Nama lengkap', 'role' => 'Peran', 'password' => 'Password awal'])->validate();

        $supplied = $data['password'] !== null;
        if ($supplied) {
            $err = PasswordPolicy::check((string) $data['password'], $data['username']);
            if ($err !== null) {
                throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['password' => $err]);
            }
        }
        $plain = $supplied ? (string) $data['password'] : PasswordPolicy::generate();

        try {
            $id = Database::transaction(function () use ($data, $plain, $actor): int {
                $id = Database::insert('users', [
                    'username'             => $data['username'],
                    'password_hash'        => password_hash($plain, PASSWORD_DEFAULT),
                    'name'                 => $data['name'],
                    'initials'             => self::initialsFor((string) $data['name']),
                    'role'                 => $data['role'],
                    'must_change_password' => 1,
                ]);
                ChangeFeed::emit('user', $id, 'created', (int) $actor['id']);
                return $id;
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['username' => 'Username sudah digunakan.']);
            }
            throw $e;
        }

        $user = self::find($id);
        AuditService::log('user.created', (int) $actor['id'], 'user', $id, null, ['username' => $data['username'], 'name' => $data['name'], 'role' => $data['role']], $request);
        // Password yang diketik admin tidak dikembalikan; yang dibuat sistem dikembalikan SEKALI untuk diserahkan ke pengguna.
        return ['user' => $user ?? [], 'temporary_password' => $supplied ? null : $plain];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function update(int $id, array $input, array $actor, Request $request): array
    {
        $data = Validator::make($input, [
            'name' => 'required|string|min:2|max:100',
            'role' => 'required|in:admin,staff',
        ], ['name' => 'Nama lengkap', 'role' => 'Peran'])->validate();

        $before = Database::transaction(function () use ($id, $data, $actor): array {
            $target = self::lock($id);
            $roleChanged = $target['role'] !== $data['role'];
            if ($roleChanged) {
                if ($id === (int) $actor['id']) {
                    throw new HttpException(409, 'self_action', 'Anda tidak dapat mengubah peran akun sendiri.');
                }
                if ($target['role'] === 'admin' && (int) $target['is_active'] === 1) {
                    self::assertAnotherActiveAdmin($id);
                }
            }
            Database::update('users', [
                'name'     => $data['name'],
                'initials' => $data['name'] !== $target['name'] ? self::initialsFor((string) $data['name']) : $target['initials'],
                'role'     => $data['role'],
            ], ['id' => $id]);
            if ($roleChanged) {
                Database::run('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$id]);
            }
            ChangeFeed::emit('user', $id, 'updated', (int) $actor['id'], $id);
            return $target;
        });

        $after = self::find($id) ?? [];
        AuditService::log('user.updated', (int) $actor['id'], 'user', $id,
            ['name' => $before['name'], 'role' => $before['role']], ['name' => $after['name'] ?? null, 'role' => $after['role'] ?? null], $request);
        return $after;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public static function setActive(int $id, bool $active, array $actor, Request $request): array
    {
        Database::transaction(function () use ($id, $active, $actor): void {
            $target = self::lock($id);
            if ($id === (int) $actor['id']) {
                throw new HttpException(409, 'self_action', 'Anda tidak dapat menonaktifkan akun sendiri.');
            }
            if ((int) $target['is_active'] === ($active ? 1 : 0)) {
                return; // sudah dalam keadaan yang diminta (idempoten)
            }
            if (!$active) {
                $open = (int) Database::value(
                    "SELECT COUNT(*) FROM loans WHERE user_id = ? AND status IN ('pending','approved')",
                    [$id]
                );
                if ($open > 0) {
                    throw new HttpException(409, 'open_loans', 'Pengguna ini masih memiliki pengajuan atau pinjaman aktif. Selesaikan terlebih dahulu.');
                }
                if ($target['role'] === 'admin') {
                    self::assertAnotherActiveAdmin($id);
                }
            }
            Database::run('UPDATE users SET is_active = ?, auth_version = auth_version + 1 WHERE id = ?', [$active ? 1 : 0, $id]);
            ChangeFeed::emit('user', $id, $active ? 'activated' : 'deactivated', (int) $actor['id'], $id);
        });

        AuditService::log($active ? 'user.activated' : 'user.deactivated', (int) $actor['id'], 'user', $id, null, null, $request);
        return self::find($id) ?? [];
    }

    /**
     * Reset password oleh admin: membuat password sementara, wajib diganti saat login berikutnya,
     * dan semua sesi aktif pengguna itu berakhir.
     *
     * @param array<string,mixed> $actor
     * @return array{user:array<string,mixed>,temporary_password:string}
     */
    public static function resetPassword(int $id, array $actor, Request $request): array
    {
        $plain = PasswordPolicy::generate();
        Database::transaction(function () use ($id, $plain, $actor): void {
            self::lock($id);
            if ($id === (int) $actor['id']) {
                throw new HttpException(409, 'self_action', 'Gunakan menu Profil untuk mengganti password Anda sendiri.');
            }
            Database::run(
                'UPDATE users SET password_hash = ?, must_change_password = 1, auth_version = auth_version + 1 WHERE id = ?',
                [password_hash($plain, PASSWORD_DEFAULT), $id]
            );
            Database::run('DELETE FROM login_attempts WHERE username = (SELECT username FROM users WHERE id = ?)', [$id]);
            ChangeFeed::emit('user', $id, 'password_reset', (int) $actor['id'], $id);
        });
        AuditService::log('user.password_reset', (int) $actor['id'], 'user', $id, null, null, $request);
        return ['user' => self::find($id) ?? [], 'temporary_password' => $plain];
    }

    /** Inisial = huruf pertama tiap kata, maksimal 2 (aturan lama). Nama satu kata → 1 huruf. */
    public static function initialsFor(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '') {
                $out .= mb_strtoupper(mb_substr($w, 0, 1));
            }
            if (mb_strlen($out) >= 2) {
                break;
            }
        }
        return $out === '' ? '?' : $out;
    }

    /** @return array<string,mixed> baris user yang dikunci (FOR UPDATE) */
    private static function lock(int $id): array
    {
        $row = Database::one('SELECT id, username, name, initials, role, is_active FROM users WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Pengguna tidak ditemukan.');
        }
        return $row;
    }

    /** Pastikan sistem tidak kehilangan admin aktif terakhir. Memanggil dalam transaksi. */
    private static function assertAnotherActiveAdmin(int $excludeId): void
    {
        Database::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE");
        $n = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$excludeId]);
        if ($n < 1) {
            throw new HttpException(409, 'last_admin', 'Harus ada minimal satu admin aktif.');
        }
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function present(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['is_active'] = (int) $r['is_active'] === 1;
        $r['must_change_password'] = (int) $r['must_change_password'] === 1;
        if (isset($r['open_loans'])) {
            $r['open_loans'] = (int) $r['open_loans'];
        }
        return $r;
    }
}
