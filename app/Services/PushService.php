<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Models\Setting;
use PDOException;
use Throwable;

/**
 * Notifikasi push ke HP/PC (PWA). Sumbernya adalah baris `notifications` (lonceng): setiap notifikasi baru otomatis
 * diantrekan ke semua perangkat aktif milik penerima (NotificationService::notify → enqueueFor).
 *
 * Jaminan:
 *  - Tidak ada push sebelum transaksi bisnis tersimpan: notify() hanya dipanggil pasca-komit.
 *  - Tidak ganda: UNIQUE(notification_id, subscription_id) + notifications.dedupe_key (pengingat terjadwal).
 *  - Dikirim SETELAH respons ke browser atau oleh penjadwal/cron; kegagalan tidak pernah menggagalkan transaksi.
 *  - Retry bertahap (1, 5, 15, 60, 180 menit; maks. 5); langganan kedaluwarsa (404/410) otomatis dinonaktifkan.
 *  - Pengingat basi (jadwal berubah/selesai) dibatalkan sebelum dikirim.
 *  - Endpoint langganan hanya diterima dari layanan push resmi (daftar putih host) → bukan celah SSRF.
 */
final class PushService
{
    public const MAX_ATTEMPTS = 5;
    public const MAX_DEVICES_PER_USER = 10;
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 180];
    private const TTL_HOURS = 48;

    /** Host layanan push resmi (persis atau akhiran ".host"). */
    private const ALLOWED_HOSTS = [
        'fcm.googleapis.com', 'android.googleapis.com',
        'updates.push.services.mozilla.com', 'push.services.mozilla.com',
        'push.apple.com', 'notify.windows.com',
    ];

    private static bool $queued = false;

    public static function hasQueued(): bool
    {
        return self::$queued;
    }

    // ============================================================================ konfigurasi

    public static function configured(): bool
    {
        return WebPush::configured();
    }

    public static function enabled(): bool
    {
        return Setting::get('push_enabled', '1') !== '0';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return ['configured' => self::configured() && self::enabled(), 'public_key' => self::configured() ? (string) Config::get('push.vapid_public') : null];
    }

    /** Endpoint harus https:443 ke host layanan push resmi; tanpa userinfo, tanpa IP literal. */
    public static function endpointAllowed(string $endpoint): bool
    {
        if (strlen($endpoint) > 700 || preg_match('/[\x00-\x20\x7F]/', $endpoint)) {
            return false;
        }
        $p = parse_url($endpoint);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }
        $allowed = self::ALLOWED_HOSTS;
        foreach (preg_split('/[\s,;]+/', (string) Config::get('push.extra_hosts', '')) ?: [] as $h) {
            if ($h !== '') {
                $allowed[] = strtolower($h);
            }
        }
        foreach ($allowed as $a) {
            if ($host === $a || substr($host, -(strlen($a) + 1)) === '.' . $a) {
                return true;
            }
        }
        return false;
    }

    // ============================================================================ langganan

    /**
     * Daftarkan/perbarui perangkat pengguna. $input = PushSubscription.toJSON(): {endpoint, keys:{p256dh,auth}}.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function subscribe(array $user, array $input, ?string $userAgent, Request $request): array
    {
        $endpoint = $input['endpoint'] ?? null;
        $keys = $input['keys'] ?? null;
        $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;
        $err = [];
        if (!is_string($endpoint) || !self::endpointAllowed($endpoint)) {
            $err['endpoint'] = 'Endpoint push tidak valid atau bukan dari layanan push yang didukung.';
        }
        try {
            if (!is_string($p256dh) || strlen(WebPush::b64uDecode($p256dh)) !== 65 || WebPush::b64uDecode($p256dh)[0] !== "\x04") {
                $err['keys'] = 'Kunci langganan tidak valid.';
            } elseif (!is_string($auth) || strlen(WebPush::b64uDecode($auth)) !== 16) {
                $err['keys'] = 'Kunci langganan tidak valid.';
            }
        } catch (Throwable $e) {
            $err['keys'] = 'Kunci langganan tidak valid.';
        }
        if ($err !== []) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', $err);
        }
        $hash = hash('sha256', (string) $endpoint);
        $ua = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
        $label = self::label($ua);

        $id = Database::transaction(function () use ($user, $endpoint, $hash, $p256dh, $auth, $ua, $label): int {
            $row = Database::one('SELECT id, user_id FROM push_subscriptions WHERE endpoint_hash = ? FOR UPDATE', [$hash]);
            if ($row !== null) {
                // endpoint yang sama dipakai akun lain di perangkat ini → pindah ke pengguna yang sedang masuk
                Database::run(
                    'UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, label = ?, is_active = 1, failure_count = 0, last_error = NULL WHERE id = ?',
                    [(int) $user['id'], $p256dh, $auth, $ua, $label, (int) $row['id']]
                );
                return (int) $row['id'];
            }
            $id = Database::insert('push_subscriptions', [
                'user_id' => (int) $user['id'], 'endpoint' => $endpoint, 'endpoint_hash' => $hash,
                'p256dh' => $p256dh, 'auth' => $auth, 'user_agent' => $ua, 'label' => $label,
            ]);
            // batasi jumlah perangkat per pengguna: yang terlama dibuang
            $extra = Database::all(
                'SELECT id FROM push_subscriptions WHERE user_id = ? ORDER BY is_active DESC, COALESCE(last_success_at, created_at) DESC, id DESC LIMIT 1000 OFFSET ' . self::MAX_DEVICES_PER_USER,
                [(int) $user['id']]
            );
            foreach ($extra as $e) {
                Database::run('DELETE FROM push_subscriptions WHERE id = ?', [(int) $e['id']]);
            }
            return $id;
        });
        AuditService::log('push.subscribed', (int) $user['id'], 'push', $id, null, ['device' => $label], $request);
        return ['id' => $id, 'label' => $label];
    }

    /** Hapus langganan milik SENDIRI berdasarkan endpoint (mis. saat keluar/logout atau mematikan notifikasi). @param array<string,mixed> $user */
    public static function unsubscribe(array $user, $endpoint, Request $request): array
    {
        if (!is_string($endpoint) || $endpoint === '' || strlen($endpoint) > 700) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['endpoint' => 'Endpoint tidak valid.']);
        }
        $n = Database::run('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), (int) $user['id']])->rowCount();
        if ($n > 0) {
            AuditService::log('push.unsubscribed', (int) $user['id'], 'push', null, null, null, $request);
        }
        return ['removed' => $n];
    }

    /** @param array<string,mixed> $user @return array<int,array<string,mixed>> perangkat milik sendiri (tanpa endpoint/kunci) */
    public static function devices(array $user): array
    {
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'label' => (string) ($r['label'] ?? 'Perangkat'), 'active' => (int) $r['is_active'] === 1,
            'created_at' => (string) $r['created_at'], 'last_success_at' => $r['last_success_at'], 'error' => $r['last_error'],
        ], Database::all('SELECT id, label, is_active, created_at, last_success_at, last_error FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC', [(int) $user['id']]));
    }

    /** @param array<string,mixed> $user */
    public static function removeDevice(array $user, int $id, Request $request): array
    {
        $n = Database::run('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, (int) $user['id']])->rowCount();
        if ($n === 0) {
            throw new HttpException(404, 'not_found', 'Perangkat tidak ditemukan.');
        }
        AuditService::log('push.device_removed', (int) $user['id'], 'push', $id, null, null, $request);
        return ['removed' => $n];
    }

    /** "Chrome · Android" dari User-Agent (hanya untuk membedakan perangkat di daftar). */
    public static function label(?string $ua): string
    {
        $ua = (string) $ua;
        $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iPod/i', $ua) ? 'iOS' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Perangkat'))));
        $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/Chrome\/|CriOS/', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser')))));
        return $br . ' · ' . $os;
    }

    // ============================================================================ antrean

    /** Antrekan notifikasi $notificationId ke semua perangkat aktif milik $userId. Tidak pernah melempar. */
    public static function enqueueFor(int $notificationId, int $userId): int
    {
        try {
            if (!self::configured() || !self::enabled()) {
                return 0;
            }
            $n = 0;
            foreach (Database::all('SELECT id FROM push_subscriptions WHERE user_id = ? AND is_active = 1', [$userId]) as $s) {
                try {
                    Database::insert('push_outbox', ['notification_id' => $notificationId, 'subscription_id' => (int) $s['id']]);
                    $n++;
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }
                }
            }
            if ($n > 0) {
                self::$queued = true;
            }
            return $n;
        } catch (Throwable $e) {
            Logger::error('Antrean push gagal ditulis', ['notif' => $notificationId, 'err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Kirim yang jatuh tempo. Aman dipanggil bersamaan dari beberapa proses (klaim atomik per baris).
     *
     * @return array{sent:int,failed:int,retry:int,gone:int,cancelled:int,configured:bool}
     */
    public static function drain(int $limit = 20, int $budgetSeconds = 25): array
    {
        self::$queued = false;
        $res = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'gone' => 0, 'cancelled' => 0, 'configured' => self::configured()];
        try {
            Database::run(
                "UPDATE push_outbox SET status = 'failed', locked_until = NULL, last_error = 'Kedaluwarsa: tidak terkirim dalam " . self::TTL_HOURS . " jam'
                 WHERE status = 'pending' AND created_at < (NOW() - INTERVAL " . self::TTL_HOURS . ' HOUR)'
            );
            if (!$res['configured']) {
                return $res;   // kunci VAPID belum diisi: baris tetap menunggu (notifikasi dalam aplikasi tetap tampil)
            }
            $started = time();
            for ($i = 0; $i < max(1, $limit); $i++) {
                if (time() - $started > $budgetSeconds) {
                    break;
                }
                $row = self::claim();
                if ($row === null) {
                    break;
                }
                self::process($row, $res);
            }
        } catch (Throwable $e) {
            Logger::error('Pengiriman push terhenti', ['err' => $e->getMessage()]);
        }
        return $res;
    }

    /** @return array<string,mixed>|null */
    private static function claim(): ?array
    {
        $cond = "((status = 'pending' AND next_attempt_at <= NOW()) OR (status = 'sending' AND locked_until < NOW()))";
        $id = Database::value("SELECT id FROM push_outbox WHERE {$cond} ORDER BY id LIMIT 1");
        if ($id === null) {
            return null;
        }
        $n = Database::run("UPDATE push_outbox SET status = 'sending', locked_until = NOW() + INTERVAL 90 SECOND WHERE id = ? AND {$cond}", [(int) $id])->rowCount();
        return $n === 1 ? Database::one('SELECT * FROM push_outbox WHERE id = ?', [(int) $id]) : self::claim();
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $res */
    private static function process(array $row, array &$res): void
    {
        $id = (int) $row['id'];
        $n = Database::one('SELECT id, user_id, type, title, body, link, entity_type, entity_id, due_ref FROM notifications WHERE id = ?', [(int) $row['notification_id']]);
        $s = Database::one('SELECT id, endpoint, p256dh, auth, is_active FROM push_subscriptions WHERE id = ?', [(int) $row['subscription_id']]);
        if ($n === null || $s === null || (int) $s['is_active'] !== 1) {
            Database::run("UPDATE push_outbox SET status = 'cancelled', locked_until = NULL, last_error = 'Perangkat/notifikasi sudah tidak ada atau nonaktif' WHERE id = ?", [$id]);
            $res['cancelled']++;
            return;
        }
        if (self::isStale($n)) {
            Database::run("UPDATE push_outbox SET status = 'cancelled', locked_until = NULL, last_error = 'Dibatalkan: jadwal berubah atau transaksi sudah selesai' WHERE id = ?", [$id]);
            $res['cancelled']++;
            return;
        }
        $attempt = (int) $row['attempts'] + 1;
        $payload = (string) json_encode([
            'title' => (string) $n['title'], 'body' => (string) ($n['body'] ?? ''), 'url' => url((string) ($n['link'] ?: '/notifications')),
            'tag' => 'hme-' . $n['id'], 'id' => (int) $n['id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $urgent = in_array((string) $n['type'], ['loan.overdue', 'pqa.overdue', 'loan.due_soon', 'pqa.due_soon'], true);
        try {
            $r = WebPush::send(['endpoint' => (string) $s['endpoint'], 'p256dh' => (string) $s['p256dh'], 'auth' => (string) $s['auth']], $payload, 86400, $urgent ? 'high' : 'normal');
        } catch (Throwable $e) {
            $r = ['status' => 0, 'body' => 'Galat lokal: ' . $e->getMessage(), 'headers' => []];
        }
        $st = (int) $r['status'];
        if ($st >= 200 && $st < 300) {
            Database::run("UPDATE push_outbox SET status = 'sent', attempts = ?, http_status = ?, sent_at = NOW(), locked_until = NULL, last_error = NULL WHERE id = ?", [$attempt, $st, $id]);
            Database::run('UPDATE push_subscriptions SET last_success_at = NOW(), failure_count = 0, last_error = NULL WHERE id = ?', [(int) $s['id']]);
            $res['sent']++;
            return;
        }
        $err = mb_substr('HTTP ' . $st . ': ' . trim((string) $r['body']), 0, 480);
        if ($st === 404 || $st === 410) {   // langganan sudah tidak berlaku (aplikasi dihapus / izin dicabut)
            Database::run("UPDATE push_outbox SET status = 'gone', attempts = ?, http_status = ?, locked_until = NULL, last_error = 'Perangkat tidak lagi berlangganan' WHERE id = ?", [$attempt, $st, $id]);
            Database::run("UPDATE push_subscriptions SET is_active = 0, last_error = 'Langganan berakhir (HTTP {$st})' WHERE id = ?", [(int) $s['id']]);
            $res['gone']++;
            return;
        }
        $retryable = $st === 0 || $st === 429 || $st >= 500;
        Database::run('UPDATE push_subscriptions SET failure_count = LEAST(255, failure_count + 1), last_error = ? WHERE id = ?', [mb_substr($err, 0, 290), (int) $s['id']]);
        if (!$retryable || $attempt >= self::MAX_ATTEMPTS) {
            Database::run("UPDATE push_outbox SET status = 'failed', attempts = ?, http_status = ?, locked_until = NULL, last_error = ? WHERE id = ?", [$attempt, $st, $err, $id]);
            $res['failed']++;
        } else {
            $wait = self::BACKOFF_MINUTES[min($attempt - 1, count(self::BACKOFF_MINUTES) - 1)];
            if ($st === 429 && isset($r['headers']['retry-after']) && ctype_digit($r['headers']['retry-after'])) {
                $wait = max($wait, min(180, (int) ceil(((int) $r['headers']['retry-after']) / 60)));
            }
            Database::run(
                "UPDATE push_outbox SET status = 'pending', attempts = ?, http_status = ?, locked_until = NULL, last_error = ?, next_attempt_at = NOW() + INTERVAL {$wait} MINUTE WHERE id = ?",
                [$attempt, $st, $err, $id]
            );
            $res['retry']++;
        }
        Logger::error('Push gagal dikirim', ['outbox_id' => $id, 'attempt' => $attempt, 'http' => $st]);
    }

    /**
     * Pengingat berjadwal yang jadwal acuannya sudah berubah / transaksinya selesai tidak boleh terkirim.
     *
     * @param array<string,mixed> $n baris notifications
     */
    public static function isStale(array $n): bool
    {
        if ($n['due_ref'] === null || $n['entity_id'] === null) {
            return false;
        }
        $ref = (string) $n['due_ref'];
        $eid = (int) $n['entity_id'];
        if ($n['entity_type'] === 'loan_batch') {
            return (int) Database::value("SELECT COUNT(*) FROM loans WHERE batch_id = ? AND status = 'approved' AND due_at = ?", [$eid, $ref]) === 0;
        }
        if ($n['entity_type'] === 'pqa') {
            return (int) Database::value("SELECT COUNT(*) FROM pqa_installations WHERE id = ? AND status IN ('Terpasang','Req Cabut') AND due_at = ?", [$eid, $ref]) === 0;
        }
        return false;
    }

    // ============================================================================ aksi pengguna & admin

    /** Kirim notifikasi uji ke perangkat milik SENDIRI (lewat jalur yang sama dengan notifikasi sungguhan). @param array<string,mixed> $user */
    public static function sendTest(array $user, Request $request): array
    {
        if (!self::configured() || !self::enabled()) {
            throw new HttpException(409, 'push_not_configured', 'Notifikasi HP belum diaktifkan di server (kunci VAPID belum diisi). Hubungi admin.');
        }
        $devices = (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND is_active = 1', [(int) $user['id']]);
        if ($devices === 0) {
            throw new HttpException(409, 'push_no_device', 'Belum ada perangkat yang mengaktifkan notifikasi. Aktifkan dulu di perangkat ini.');
        }
        $key = 'test:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
        NotificationService::notify([(int) $user['id']], 'push.test', 'Notifikasi uji', 'Bila ini muncul di HP Anda, notifikasi sudah berfungsi.', '/notifications', null, ['dedupe_key' => $key]);
        $res = self::drain(10, 15);
        AuditService::log('push.test', (int) $user['id'], 'push', null, null, ['devices' => $devices, 'result' => $res], $request);
        return ['devices' => $devices] + $res;
    }

    /** @return array<string,mixed> ringkasan untuk halaman Pengaturan (admin) */
    public static function status(): array
    {
        $c = static fn(string $st): int => (int) Database::value('SELECT COUNT(*) FROM push_outbox WHERE status = ?', [$st]);
        return [
            'configured' => self::configured(), 'enabled' => self::enabled(),
            'public_key_set' => (string) Config::get('push.vapid_public', '') !== '',
            'subject' => (string) Config::get('push.subject', ''),
            'devices' => (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE is_active = 1'),
            'users' => (int) Database::value('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions WHERE is_active = 1'),
            'pending' => $c('pending'), 'sent' => $c('sent'), 'failed' => $c('failed'), 'gone' => $c('gone'), 'cancelled' => $c('cancelled'),
            'last_success_at' => Database::value("SELECT MAX(sent_at) FROM push_outbox WHERE status = 'sent'"),
        ];
    }

    /** @param array<string,mixed> $filters status @return array<string,mixed> */
    public static function log(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = '';
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'sending', 'sent', 'failed', 'gone', 'cancelled'], true)) {
            $where = ' WHERE o.status = ?';
            $params[] = $filters['status'];
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $total = (int) Database::value('SELECT COUNT(*) FROM push_outbox o' . $where, $params);
        $rows = Database::all(
            'SELECT o.id, o.status, o.attempts, o.http_status, o.last_error, o.created_at, o.sent_at, o.next_attempt_at,
                    n.title, n.type, u.name AS user_name, s.label
             FROM push_outbox o JOIN notifications n ON n.id = o.notification_id JOIN push_subscriptions s ON s.id = o.subscription_id
             JOIN users u ON u.id = s.user_id' . $where . ' ORDER BY o.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        return [
            'items' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'status' => (string) $r['status'], 'attempts' => (int) $r['attempts'], 'http_status' => $r['http_status'] === null ? null : (int) $r['http_status'],
                'error' => $r['last_error'], 'title' => (string) $r['title'], 'type' => (string) $r['type'], 'user' => (string) $r['user_name'], 'device' => (string) ($r['label'] ?? ''),
                'created_at' => (string) $r['created_at'], 'sent_at' => $r['sent_at'], 'next_attempt_at' => $r['status'] === 'pending' ? (string) $r['next_attempt_at'] : null,
            ], $rows),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
        ];
    }

    /** Coba kirim ulang baris gagal (admin). @param array<string,mixed> $actor */
    public static function retry(int $id, array $actor, Request $request): array
    {
        $row = Database::one('SELECT id, status FROM push_outbox WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Pesan tidak ditemukan.');
        }
        if ($row['status'] !== 'failed') {
            throw new HttpException(409, 'invalid_state', 'Hanya pesan yang gagal yang dapat dikirim ulang.');
        }
        Database::run("UPDATE push_outbox SET status = 'pending', attempts = 0, last_error = NULL, locked_until = NULL, next_attempt_at = NOW() WHERE id = ?", [$id]);
        self::$queued = true;
        AuditService::log('push.retry', (int) $actor['id'], 'push', $id, null, null, $request);
        return ['id' => $id, 'status' => 'pending'];
    }
}
