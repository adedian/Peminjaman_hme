<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Realtime (§7 rencana): change-feed + polling adaptif.
 *
 *  GET /api/v1/sync?since=<id>  → null (= 204, tidak ada yang relevan) atau delta entitas yang berubah.
 *  - Satu query rentang PK pada change_events; sesi dilepas sebelum query (tidak mengunci request lain).
 *  - Hanya mengirim ID & jenis entitas, bukan isinya: halaman memuat ulang datanya lewat API biasa, sehingga
 *    aturan akses tetap ditegakkan di satu tempat. Delta tetap disaring per peran agar tidak membocorkan
 *    keberadaan aktivitas orang lain (staff hanya melihat pengajuan miliknya dan notifikasinya sendiri).
 *  - Terlalu banyak perubahan atau kursor tidak valid → reset (klien memuat ulang semua yang tampil).
 */
final class SyncService
{
    public const MAX_EVENTS = 500;
    private const TYPES = ['request', 'item', 'pqa', 'user', 'notification'];

    /** ID perubahan terakhir — ditanam di halaman sebagai titik awal polling. */
    public static function cursor(): int
    {
        return (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM change_events');
    }

    /**
     * @param array<string,mixed> $viewer
     * @return array<string,mixed>|null null = tidak ada perubahan yang relevan
     */
    public static function poll(array $viewer, int $since): ?array
    {
        self::maybePrune();
        $uid = (int) $viewer['id'];
        $admin = ($viewer['role'] ?? '') === 'admin';

        $rows = Database::all(
            'SELECT id, entity_type, entity_id, action, user_id FROM change_events WHERE id > ? ORDER BY id LIMIT ?',
            [$since, self::MAX_EVENTS + 1]
        );
        if ($rows === []) {
            if ($since > 0 && $since > self::cursor()) {
                return self::reset($viewer);   // database diganti/dipulihkan: kursor klien di masa depan
            }
            return null;
        }
        if (count($rows) > self::MAX_EVENTS) {
            return self::reset($viewer);
        }
        $cursor = (int) $rows[count($rows) - 1]['id'];

        $changes = array_fill_keys(self::TYPES, []);
        $requestIds = [];
        foreach ($rows as $r) {
            $type = (string) $r['entity_type'];
            $id = (string) $r['entity_id'];
            switch ($type) {
                case 'notification':
                    if ((int) $r['user_id'] === $uid) {
                        $changes['notification'][$id] = true;
                    }
                    break;
                case 'request':
                    $requestIds[$id] = true;
                    break;
                case 'user':
                    if ($admin || $id === (string) $uid) {
                        $changes['user'][$id] = true;
                    }
                    break;
                case 'item':
                case 'pqa':
                    $changes[$type][$id] = true;   // ketersediaan barang & tracker PQA memang dibagi bersama
                    break;
            }
        }
        if ($requestIds !== []) {
            $ids = array_map('intval', array_keys($requestIds));
            if (!$admin) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $ids = array_map('intval', array_column(
                    Database::all("SELECT id FROM loan_batches WHERE id IN ({$in}) AND user_id = ?", array_merge($ids, [$uid])), 'id'
                ));
            }
            foreach ($ids as $id) {
                $changes['request'][(string) $id] = true;
            }
        }

        $out = [];
        foreach ($changes as $type => $set) {
            if ($set !== []) {
                $out[$type] = array_map('intval', array_keys($set));
            }
        }
        if ($out === []) {
            return ['cursor' => $cursor, 'reset' => false, 'changes' => (object) [], 'counts' => null, 'notifications' => []];
        }
        return [
            'cursor'        => $cursor,
            'reset'         => false,
            'changes'       => $out,
            'counts'        => self::counts($viewer),
            'notifications' => isset($out['notification']) ? self::newNotifications($uid, $out['notification']) : [],
        ];
    }

    /** Angka lencana & lonceng. @param array<string,mixed> $viewer @return array<string,int> */
    public static function counts(array $viewer): array
    {
        $c = ['notifications' => NotificationService::unreadCount((int) $viewer['id'])];
        if (($viewer['role'] ?? '') === 'admin') {
            $c['requests'] = LoanService::pendingRequestCount();
            $c['pqa'] = PqaService::pendingActionCount();
        }
        return $c;
    }

    /** @param array<string,mixed> $viewer @return array<string,mixed> */
    private static function reset(array $viewer): array
    {
        return ['cursor' => self::cursor(), 'reset' => true, 'changes' => (object) [], 'counts' => self::counts($viewer), 'notifications' => []];
    }

    /** Notifikasi baru (belum dibaca) untuk toast. @param array<int,int> $ids @return array<int,array<string,mixed>> */
    private static function newNotifications(int $uid, array $ids): array
    {
        $ids = array_slice($ids, -5);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT id, type, title, body, link FROM notifications WHERE user_id = ? AND read_at IS NULL AND id IN ({$in}) ORDER BY id",
            array_merge([$uid], $ids)
        );
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'type' => (string) $r['type'], 'title' => (string) $r['title'],
            'body' => $r['body'] === null ? null : (string) $r['body'], 'link' => $r['link'] === null ? null : url((string) $r['link']),
        ], $rows);
    }

    /** Bersihkan feed lama sesekali (±1 dari 200 polling). Klien dengan kursor lama tetap aman (id > since). */
    private static function maybePrune(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }
        try {
            Database::run('DELETE FROM change_events WHERE created_at < NOW() - INTERVAL 2 DAY ORDER BY id LIMIT 5000');
        } catch (Throwable $e) {
            Logger::warning('Pembersihan change_events gagal', ['err' => $e->getMessage()]);
        }
    }
}
