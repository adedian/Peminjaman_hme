<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Helpers\Idn;
use App\Models\Setting;
use Throwable;

/**
 * Penjadwal pengingat (revisi V2). Dijalankan CRON setiap 5 menit lewat `php tools/scheduler.php` — tidak bergantung
 * pada browser/JavaScript dan tetap jalan ketika tidak ada pengguna yang membuka aplikasi.
 *
 * Tiap putaran: (1) batalkan pengingat basi, (2) susun pengingat yang sudah waktunya sebagai notifikasi aplikasi
 * (lonceng) — yang otomatis diteruskan sebagai push ke HP penerima, (3) kirim antrean push & email tertunda,
 * (4) catat satu baris scheduler_runs. Aman dijalankan tumpang tindih (kunci GET_LOCK) dan aman diulang
 * (notifications.dedupe_key UNIQUE: pengingat yang sama tidak pernah dua kali).
 *
 * Aturan waktu (semua memakai jam database = Asia/Jakarta):
 *  - Mendekati jatuh tempo: sisa waktu <= min(jendela pengingat, setengah dari lama pinjam/pasang). Jendela bawaan 24 jam
 *    (H-1). Setengah-lama dipakai agar peminjaman yang lebih singkat dari jendela tidak langsung diberi "pengingat" saat
 *    baru disetujui.
 *  - Melewati batas: tepat sekali per versi jadwal (kunci memuat due_at). Diperpanjang → due_at berubah → kunci baru →
 *    pengingat H-1 dan peringatan dihitung ulang dari jadwal baru; push lama yang belum terkirim dibatalkan.
 */
final class SchedulerService
{
    private const LOCK = 'hme_scheduler';

    /**
     * @return array{ran:bool,enqueued:int,cancelled:int,sent:int,failed:int,detail:string,error:?string}
     */
    public static function tick(string $trigger = 'cron'): array
    {
        $res = ['ran' => false, 'enqueued' => 0, 'cancelled' => 0, 'sent' => 0, 'failed' => 0, 'detail' => '', 'error' => null];
        $got = (int) Database::value('SELECT GET_LOCK(?, 0)', [self::LOCK]);
        if ($got !== 1) {
            $res['detail'] = 'Putaran lain sedang berjalan';
            return $res;
        }
        $run = Database::insert('scheduler_runs', ['trigger_by' => $trigger]);
        try {
            $res['ran'] = true;
            $res['cancelled'] += self::cancelStale();
            $parts = [];
            foreach (['loanDueSoon', 'loanOverdue', 'pqaDueSoon', 'pqaOverdue'] as $job) {
                $n = self::$job();
                $res['enqueued'] += $n;
                $parts[] = $job . '=' . $n;
            }
            $drain = PushService::drain(50, 40);
            $res['sent'] = $drain['sent'];
            $res['failed'] = $drain['failed'];
            $res['cancelled'] += $drain['cancelled'];
            $parts[] = 'push(terkirim=' . $drain['sent'] . ',ulang=' . $drain['retry'] . ',gagal=' . $drain['failed'] . ',berakhir=' . $drain['gone'] . ($drain['configured'] ? '' : ',VAPID-belum-diisi') . ')';
            try {
                MailService::drain(20);
            } catch (Throwable $e) {
                Logger::error('Penjadwal: antrean email gagal diproses', ['err' => $e->getMessage()]);
            }
            $res['detail'] = implode(' ', $parts);
            Setting::set('scheduler_last_run', date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            $res['error'] = mb_substr($e->getMessage(), 0, 400);
            Logger::error('Penjadwal gagal', ['err' => $res['error']]);
        } finally {
            Database::update('scheduler_runs', [
                'finished_at' => date('Y-m-d H:i:s'), 'enqueued' => $res['enqueued'], 'cancelled' => $res['cancelled'],
                'sent' => $res['sent'], 'failed' => $res['failed'], 'detail' => mb_substr($res['detail'], 0, 500), 'error' => $res['error'],
            ], ['id' => $run]);
            Database::run('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            if (random_int(1, 50) === 1) {
                Database::run('DELETE FROM scheduler_runs WHERE started_at < (NOW() - INTERVAL 60 DAY)');
            }
        }
        return $res;
    }

    /** Cadangan bila hosting tidak punya cron: dipanggil dari permintaan web, paling sering sekali per $minSeconds. */
    public static function tickIfStale(int $minSeconds = 300): ?array
    {
        $last = Setting::get('scheduler_last_run');
        if ($last !== null && $last !== '' && time() - (int) strtotime($last) < $minSeconds) {
            return null;
        }
        return self::tick('web');
    }

    /** @return array{last_run:?string,age_seconds:?int,ok:bool,last_error:?string} */
    public static function health(): array
    {
        $last = Setting::get('scheduler_last_run');
        $age = $last === null || $last === '' ? null : max(0, time() - (int) strtotime($last));
        $err = Database::value("SELECT error FROM scheduler_runs WHERE error IS NOT NULL ORDER BY id DESC LIMIT 1");
        return ['last_run' => $last ?: null, 'age_seconds' => $age, 'ok' => $age !== null && $age <= 900, 'last_error' => $err === null ? null : (string) $err];
    }

    // ======================================================================================
    //  Pembatalan push basi
    // ======================================================================================

    /** Push pengingat yang masih menunggu tetapi jadwal acuannya sudah berubah / transaksinya sudah selesai. */
    private static function cancelStale(): int
    {
        $n = 0;
        $rows = Database::all(
            "SELECT o.id AS oid, n.entity_type, n.entity_id, n.due_ref
             FROM push_outbox o JOIN notifications n ON n.id = o.notification_id
             WHERE o.status = 'pending' AND n.due_ref IS NOT NULL"
        );
        foreach ($rows as $r) {
            if (PushService::isStale($r)) {
                $n += Database::run(
                    "UPDATE push_outbox SET status = 'cancelled', last_error = 'Dibatalkan: jadwal berubah atau transaksi sudah selesai' WHERE id = ? AND status = 'pending'",
                    [(int) $r['oid']]
                )->rowCount();
            }
        }
        return $n;
    }

    // ======================================================================================
    //  Peminjaman
    // ======================================================================================

    private static function loanWindow(): int
    {
        return max(1, Setting::int('loan_reminder_hours', 24)) * 3600;
    }

    private static function loanDueSoon(): int
    {
        $win = self::loanWindow();
        $rows = Database::all(
            "SELECT l.batch_id, l.user_id, l.due_at, u.name AS user_name,
                    GROUP_CONCAT(l.item_name_snapshot ORDER BY l.seq_no SEPARATOR ', ') AS names,
                    TIMESTAMPDIFF(SECOND, NOW(), l.due_at) AS left_s,
                    TIMESTAMPDIFF(SECOND, MIN(COALESCE(l.decided_at, l.created_at)), l.due_at) AS total_s
             FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.status = 'approved' AND l.due_at IS NOT NULL AND l.due_at > NOW() AND l.due_at <= (NOW() + INTERVAL ? SECOND)
             GROUP BY l.batch_id, l.user_id, l.due_at, u.name",
            [$win]
        );
        $n = 0;
        foreach ($rows as $r) {
            $left = (int) $r['left_s'];
            if ($left > min($win, (int) floor(max(0, (int) $r['total_s']) / 2))) {
                continue;   // belum waktunya (peminjaman singkat: menunggu titik tengah)
            }
            $n += NotificationService::notify(
                [(int) $r['user_id']], 'loan.due_soon', 'Pengingat pengembalian',
                $r['names'] . ' harus dikembalikan paling lambat ' . Idn::dateTime((string) $r['due_at']) . ' (sisa ' . Idn::span($left) . ').',
                '/requests?open=' . (int) $r['batch_id'], null,
                ['dedupe_key' => 'loan.due_soon:batch:' . (int) $r['batch_id'] . ':' . $r['due_at'], 'entity_type' => 'loan_batch', 'entity_id' => (int) $r['batch_id'], 'due_ref' => (string) $r['due_at']]
            );
        }
        return $n;
    }

    private static function loanOverdue(): int
    {
        $rows = Database::all(
            "SELECT l.batch_id, l.user_id, l.due_at, u.name AS user_name,
                    GROUP_CONCAT(l.item_name_snapshot ORDER BY l.seq_no SEPARATOR ', ') AS names,
                    TIMESTAMPDIFF(SECOND, l.due_at, NOW()) AS late_s
             FROM loans l JOIN users u ON u.id = l.user_id
             WHERE l.status = 'approved' AND l.due_at IS NOT NULL AND l.due_at < NOW()
             GROUP BY l.batch_id, l.user_id, l.due_at, u.name"
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += NotificationService::notify(
                [(int) $r['user_id']], 'loan.overdue', 'Peminjaman terlambat',
                $r['names'] . ' melewati batas pengembalian (' . Idn::dateTime((string) $r['due_at']) . '), terlambat ' . Idn::span((int) $r['late_s']) . '. Mohon segera dikembalikan.',
                '/requests?open=' . (int) $r['batch_id'], null,
                ['dedupe_key' => 'loan.overdue:batch:' . (int) $r['batch_id'] . ':' . $r['due_at'], 'entity_type' => 'loan_batch', 'entity_id' => (int) $r['batch_id'], 'due_ref' => (string) $r['due_at']]
            );
        }
        return $n;
    }

    // ======================================================================================
    //  PQA
    // ======================================================================================

    private static function pqaWindow(): int
    {
        return max(1, Setting::int('pqa_reminder_hours', 24)) * 3600;
    }

    /** @param array<string,mixed> $r @return array<int,int> semua admin + pemohon pemasangan + pemohon perpanjangan terakhir */
    private static function pqaRecipients(array $r): array
    {
        $to = NotificationService::adminIds();
        foreach ([$r['requested_by'], Database::value("SELECT requested_by FROM pqa_requests WHERE installation_id = ? AND type = 'extend' ORDER BY id DESC LIMIT 1", [(int) $r['id']])] as $u) {
            if ($u !== null && !in_array((int) $u, $to, true)) {
                $to[] = (int) $u;
            }
        }
        return $to;
    }

    private static function pqaDueSoon(): int
    {
        $win = self::pqaWindow();
        $rows = Database::all(
            "SELECT p.id, p.requested_by, p.location, p.due_at, i.name AS item_name,
                    TIMESTAMPDIFF(SECOND, NOW(), p.due_at) AS left_s, TIMESTAMPDIFF(SECOND, p.installed_at, p.due_at) AS total_s
             FROM pqa_installations p JOIN items i ON i.id = p.item_id
             WHERE p.status IN ('Terpasang','Req Cabut') AND p.due_at IS NOT NULL AND p.due_at > NOW() AND p.due_at <= (NOW() + INTERVAL ? SECOND)",
            [$win]
        );
        $n = 0;
        foreach ($rows as $r) {
            $left = (int) $r['left_s'];
            if ($left > min($win, (int) floor(max(0, (int) $r['total_s']) / 2))) {
                continue;
            }
            $n += NotificationService::notify(
                self::pqaRecipients($r), 'pqa.due_soon', 'Pengingat pencabutan PQA',
                $r['item_name'] . ' di ' . $r['location'] . ' harus dicabut pada ' . Idn::dateTime((string) $r['due_at']) . ' (sisa ' . Idn::span($left) . ').',
                '/pqa?open=' . (int) $r['id'], null,
                ['dedupe_key' => 'pqa.due_soon:' . (int) $r['id'] . ':' . $r['due_at'], 'entity_type' => 'pqa', 'entity_id' => (int) $r['id'], 'due_ref' => (string) $r['due_at']]
            );
        }
        return $n;
    }

    private static function pqaOverdue(): int
    {
        $rows = Database::all(
            "SELECT p.id, p.requested_by, p.location, p.due_at, i.name AS item_name, TIMESTAMPDIFF(SECOND, p.due_at, NOW()) AS late_s
             FROM pqa_installations p JOIN items i ON i.id = p.item_id
             WHERE p.status IN ('Terpasang','Req Cabut') AND p.due_at IS NOT NULL AND p.due_at < NOW()"
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += NotificationService::notify(
                self::pqaRecipients($r), 'pqa.overdue', 'PQA melewati jadwal pencabutan',
                $r['item_name'] . ' di ' . $r['location'] . ' seharusnya dicabut ' . Idn::dateTime((string) $r['due_at']) . ' (terlambat ' . Idn::span((int) $r['late_s']) . '). Mohon segera dicabut.',
                '/pqa?open=' . (int) $r['id'], null,
                ['dedupe_key' => 'pqa.overdue:' . (int) $r['id'] . ':' . $r['due_at'], 'entity_type' => 'pqa', 'entity_id' => (int) $r['id'], 'due_ref' => (string) $r['due_at']]
            );
        }
        return $n;
    }
}
