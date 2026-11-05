<?php
declare(strict_types=1);

/**
 * Penjadwal pengingat (peminjaman jatuh tempo/terlambat, PQA H-1/terlambat) → notifikasi aplikasi + push ke HP.
 * Pasang di cron setiap 5 menit (lihat deploy/crontab.example). Tidak membutuhkan browser/pengguna yang online.
 *
 *   php tools/scheduler.php            jalankan satu putaran
 *   php tools/scheduler.php --status   tampilkan kondisi penjadwal & antrean, tanpa menjalankan
 *
 * Kode keluar: 0 = sukses (atau putaran lain sedang berjalan), 1 = galat.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\SchedulerService;
use App\Services\PushService;

date_default_timezone_set((string) \App\Core\Config::get('app.timezone', 'Asia/Jakarta'));
$args = array_slice($argv, 1);

try {
    if (in_array('--status', $args, true)) {
        $h = SchedulerService::health();
        $w = PushService::status();
        echo 'Penjadwal terakhir jalan : ' . ($h['last_run'] ?? 'BELUM PERNAH') . ($h['age_seconds'] !== null ? " ({$h['age_seconds']} dtk lalu)" : '') . "\n";
        echo 'Status                   : ' . ($h['ok'] ? 'sehat' : 'TERLAMBAT / belum jalan — periksa cron') . "\n";
        echo 'Notifikasi push (VAPID)    : ' . ($w['configured'] ? 'aktif' : 'BELUM dikonfigurasi (php tools/generate-vapid.php --write)') . " · {$w['devices']} perangkat\n";
        echo "Antrean push             : menunggu {$w['pending']} · gagal {$w['failed']} · terkirim {$w['sent']} · berakhir {$w['gone']}\n";
        exit($h['ok'] ? 0 : 1);
    }
    $r = SchedulerService::tick('cron');
    $stamp = date('Y-m-d H:i:s');
    if (!$r['ran']) {
        echo "[{$stamp}] dilewati: {$r['detail']}\n";
        exit(0);
    }
    echo "[{$stamp}] antre={$r['enqueued']} batal={$r['cancelled']} terkirim={$r['sent']} gagal={$r['failed']} :: {$r['detail']}\n";
    if ($r['error'] !== null) {
        fwrite(STDERR, "Galat: {$r['error']}\n");
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
