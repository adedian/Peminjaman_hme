<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Pembersihan berkala (Tahap 8) — membuang data yang sudah tidak berguna agar database dan storage tidak
 * membengkak. TIDAK menyentuh data bisnis (barang, peminjaman, PQA, arsip) maupun audit log
 * (kecuali diminta eksplisit lewat $auditDays).
 */
final class MaintenanceService
{
    /**
     * @param array{notif_days?:int, mail_days?:int, audit_days?:int|null, dry_run?:bool} $opt
     * @return array<string,int> jumlah baris/berkas yang dibuang (atau akan dibuang bila dry_run)
     */
    public static function run(array $opt = []): array
    {
        $notifDays = max(7, (int) ($opt['notif_days'] ?? 90));
        $mailDays  = max(7, (int) ($opt['mail_days'] ?? 90));
        $auditDays = isset($opt['audit_days']) ? max(90, (int) $opt['audit_days']) : null;
        $dry       = (bool) ($opt['dry_run'] ?? false);

        $out = [];
        $out['notifikasi dibaca'] = self::purge(
            'notifications',
            'read_at IS NOT NULL AND read_at < NOW() - INTERVAL ? DAY',
            [$notifDays],
            $dry
        );
        // Notifikasi yang tak pernah dibaca tetap dibuang bila sangat tua (2x batas).
        $out['notifikasi lama'] = self::purge(
            'notifications',
            'read_at IS NULL AND created_at < NOW() - INTERVAL ? DAY',
            [$notifDays * 2],
            $dry
        );
        $out['email terkirim/dilewati'] = self::purge(
            'mail_outbox',
            "status IN ('sent','skipped') AND created_at < NOW() - INTERVAL ? DAY",
            [$mailDays],
            $dry
        );
        $out['email gagal'] = self::purge(
            'mail_outbox',
            "status = 'failed' AND created_at < NOW() - INTERVAL ? DAY",
            [$mailDays * 2],
            $dry
        );
        $out['feed perubahan'] = self::purge('change_events', 'created_at < NOW() - INTERVAL 2 DAY', [], $dry);
        $out['percobaan login'] = self::purge('login_attempts', 'created_at < NOW() - INTERVAL 1 DAY', [], $dry);
        if ($auditDays !== null) {
            $out['audit log'] = self::purge('activity_logs', 'created_at < NOW() - INTERVAL ? DAY', [$auditDays], $dry);
        }

        $storage = (string) Config::get('paths.storage');
        $idle = max(
            (int) Config::get('session.idle_timeout', 7200),
            (int) Config::get('session.absolute_timeout', 43200)
        );
        $out['berkas sesi'] = self::sweepFiles($storage . '/sessions', 'sess_*', $idle + 86400, $dry);
        $out['berkas ekspor'] = self::sweepFiles($storage . '/exports', '*', 86400, $dry);
        $out['berkas cache'] = self::sweepFiles($storage . '/cache', '*', 7 * 86400, $dry);

        return $out;
    }

    /** Hapus berkas lebih tua dari $maxAge detik; .gitkeep/.htaccess tidak disentuh. */
    private static function sweepFiles(string $dir, string $pattern, int $maxAge, bool $dry): int
    {
        $n = 0;
        $limit = time() - $maxAge;
        foreach (glob($dir . '/' . $pattern) ?: [] as $f) {
            if (!is_file($f) || is_link($f) || $f[0] === '.' || str_starts_with(basename($f), '.')) {
                continue;
            }
            if (@filemtime($f) !== false && filemtime($f) < $limit) {
                if ($dry || @unlink($f)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Hapus per potongan 5.000 baris agar tidak mengunci tabel lama-lama. */
    private static function purge(string $table, string $where, array $params, bool $dry): int
    {
        if ($dry) {
            return (int) Database::run("SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params)->fetchColumn();
        }
        $total = 0;
        do {
            $stmt = Database::run("DELETE FROM `{$table}` WHERE {$where} LIMIT 5000", $params);
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === 5000);
        return $total;
    }
}
