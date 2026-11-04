<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use Throwable;

/**
 * Notifikasi in-app (lonceng). Q8: staff cukup notifikasi di aplikasi; admin mendapat notifikasi + email (BR-12).
 * Pembuatan dipanggil notifier SETELAH transaksi bisnis dikomit dan tidak pernah melempar exception.
 * Setiap notifikasi juga menambah change_events (user_id = penerima) agar Tahap 5 (/sync) dapat memperbarui lonceng.
 * Pengguna hanya bisa membaca/menandai notifikasi miliknya sendiri (milik orang lain → 404).
 */
final class NotificationService
{
    public const MAX_LIST = 50;

    /**
     * @param array<int,int> $userIds penerima (duplikat & actor dibuang)
     * @return int jumlah notifikasi dibuat
     */
    public static function notify(array $userIds, string $type, string $title, ?string $body = null, ?string $link = null, ?int $actorId = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0 && $id !== $actorId)));
        $n = 0;
        foreach ($ids as $uid) {
            try {
                $id = Database::insert('notifications', [
                    'user_id' => $uid,
                    'type'    => substr($type, 0, 40),
                    'title'   => mb_substr($title, 0, 150),
                    'body'    => $body === null ? null : mb_substr($body, 0, 500),
                    'link'    => $link === null ? null : substr($link, 0, 255),
                ]);
                ChangeFeed::emit('notification', $id, 'created', $actorId, $uid);
                $n++;
            } catch (Throwable $e) {
                Logger::error('Notifikasi gagal dibuat', ['type' => $type, 'user' => $uid, 'err' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /** Admin aktif (penerima notifikasi admin). @return array<int,int> */
    public static function adminIds(): array
    {
        return array_map('intval', array_column(
            Database::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1"), 'id'
        ));
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** @return array{items:array<int,array<string,mixed>>,unread:int,has_more:bool} */
    public static function list(int $userId, bool $unreadOnly = false, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_LIST, $limit));
        $offset = max(0, $offset);
        $rows = Database::all(
            'SELECT id, type, title, body, link, read_at, created_at FROM notifications WHERE user_id = ?'
            . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            [$userId, $limit + 1, $offset]
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'type' => (string) $r['type'], 'title' => (string) $r['title'],
                'body' => $r['body'] === null ? null : (string) $r['body'],
                'link' => $r['link'] === null ? null : url((string) $r['link']),
                'read' => $r['read_at'] !== null, 'created_at' => (string) $r['created_at'],
            ], $rows),
            'unread'   => self::unreadCount($userId),
            'has_more' => $more,
        ];
    }

    /** @return array{unread:int} */
    public static function markRead(int $id, int $userId): array
    {
        $own = Database::value('SELECT COUNT(*) FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ((int) $own === 0) {
            throw new HttpException(404, 'not_found', 'Notifikasi tidak ditemukan.');
        }
        Database::run('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, $userId]);
        return ['unread' => self::unreadCount($userId)];
    }

    /** @return array{unread:int,marked:int} */
    public static function markAllRead(int $userId): array
    {
        $n = Database::run('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId])->rowCount();
        return ['unread' => 0, 'marked' => $n];
    }
}
