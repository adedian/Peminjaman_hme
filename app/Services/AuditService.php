<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

/**
 * Audit log aktivitas (activity_logs). Rahasia (password, token, ...) selalu disamarkan sebelum disimpan,
 * dan kegagalan menulis log tidak boleh menggagalkan aksi bisnis.
 */
final class AuditService
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(
        string $action,
        ?int $userId,
        ?string $entityType = null,
        $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null
    ): void {
        try {
            Database::insert('activity_logs', [
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : substr((string) $entityId, 0, 40),
                'before_json' => $before === null ? null : self::encode($before),
                'after_json'  => $after === null ? null : self::encode($after),
                'ip'          => $request ? $request->ip() : null,
                'user_agent'  => $request ? $request->userAgent() : null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit log gagal ditulis', ['action' => $action, 'err' => $e->getMessage()]);
        }
    }

    /** Kelompok aksi untuk filter halaman Log Aktivitas → awalan nama aksi. */
    public const GROUPS = [
        'auth'     => ['auth.'],
        'loan'     => ['request.', 'loan.'],
        'pqa'      => ['pqa.'],
        'item'     => ['item.'],
        'user'     => ['user.'],
        'report'   => ['report.'],
        'settings' => ['settings.'],
    ];
    private const MAX_LIMIT = 100;

    /**
     * Daftar log untuk admin (terbaru dulu). Isi before/after sudah disamarkan saat ditulis.
     *
     * @param array<string,mixed> $f group, user_id, q, from (Y-m-d), to (Y-m-d)
     * @return array{items:array<int,array<string,mixed>>,has_more:bool}
     */
    public static function list(array $f, int $limit = 50, int $offset = 0): array
    {
        $fields = [];
        $where = [];
        $params = [];
        foreach (['group', 'user_id', 'q', 'from', 'to'] as $k) {
            if (isset($f[$k]) && !is_string($f[$k])) {
                $fields[$k] = 'Filter tidak valid.';
            }
        }
        if ($fields !== []) {
            throw new \App\Core\HttpException(422, 'validation_failed', 'Filter log belum valid.', $fields);
        }
        $group = (string) ($f['group'] ?? '');
        if ($group !== '') {
            if (!isset(self::GROUPS[$group])) {
                $fields['group'] = 'Kelompok aksi tidak dikenal.';
            } else {
                $or = [];
                foreach (self::GROUPS[$group] as $prefix) {
                    $or[] = 'a.action LIKE ?';
                    $params[] = $prefix . '%';
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        $uid = (string) ($f['user_id'] ?? '');
        if ($uid !== '') {
            if (!ctype_digit($uid)) {
                $fields['user_id'] = 'Pengguna tidak valid.';
            } else {
                $where[] = 'a.user_id = ?';
                $params[] = (int) $uid;
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            $v = (string) ($f[$k] ?? '');
            if ($v === '') {
                continue;
            }
            // Pola dulu: byte NUL membuat createFromFormat melempar ValueError (PHP 8)
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) ? \DateTime::createFromFormat('!Y-m-d', $v) : false;
            if ($d === false || $d->format('Y-m-d') !== $v) {
                $fields[$k] = 'Tanggal tidak valid.';
                continue;
            }
            if ($k === 'to') {
                $d->modify('+1 day');   // inklusif sampai akhir hari
            }
            $where[] = "a.created_at {$op} ?";
            $params[] = $d->format('Y-m-d H:i:s');
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            if (mb_strlen($q) > 100) {
                $fields['q'] = 'Kata kunci terlalu panjang.';
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(a.action LIKE ? OR a.entity_id LIKE ? OR u.username LIKE ? OR u.name LIKE ? OR a.ip LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like);
            }
        }
        if ($fields !== []) {
            throw new \App\Core\HttpException(422, 'validation_failed', 'Filter log belum valid.', $fields);
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $params[] = $limit + 1;
        $params[] = max(0, $offset);
        $rows = Database::all(
            'SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.ip, a.user_agent, a.created_at,
                    u.name AS user_name, u.username, u.role
             FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            $params
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $dec = static function ($j) {
            if ($j === null) {
                return null;
            }
            $v = json_decode((string) $j, true);
            return is_array($v) ? $v : null;
        };
        return [
            'items' => array_map(static fn(array $r): array => [
                'id'          => (int) $r['id'],
                'action'      => (string) $r['action'],
                'entity_type' => $r['entity_type'],
                'entity_id'   => $r['entity_id'],
                'user'        => $r['user_id'] === null ? null : [
                    'id' => (int) $r['user_id'], 'name' => (string) ($r['user_name'] ?? ''), 'username' => (string) ($r['username'] ?? ''), 'role' => $r['role'],
                ],
                'before'      => $dec($r['before_json']),
                'after'       => $dec($r['after_json']),
                'ip'          => $r['ip'],
                'user_agent'  => $r['user_agent'],
                'created_at'  => substr((string) $r['created_at'], 0, 19),
            ], $rows),
            'has_more' => $more,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        $json = json_encode(Logger::redact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? '{}' : $json;
    }
}
