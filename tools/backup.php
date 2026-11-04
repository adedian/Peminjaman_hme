<?php
declare(strict_types=1);

/**
 * Backup database + foto barang ke satu arsip .zip.
 *
 *   php tools/backup.php                          → BACKUP_DIR (default storage/backups)/hme-backup-YYYYMMDD-HHMMSS.zip
 *   php tools/backup.php --dir=/home/user/backups  folder tujuan (sebaiknya DI LUAR folder web)
 *   php tools/backup.php --keep=14                 simpan 14 backup terbaru, hapus yang lebih lama (default 14)
 *   php tools/backup.php --no-uploads              hanya database
 *
 * Arsip TIDAK menyertakan .env (rahasia) — simpan terpisah. Hanya baca dari database; aman dijalankan
 * saat aplikasi dipakai. Keluar dengan kode 1 bila gagal (cocok untuk cron + pemberitahuan).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\BackupService;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: {$a}\n");
        exit(2);
    }
}
$dir  = is_string($opts['dir'] ?? null) ? (string) $opts['dir'] : (string) Config::get('paths.backups');
$keep = isset($opts['keep']) ? (int) $opts['keep'] : 14;
if ($keep < 1) {
    fwrite(STDERR, "--keep minimal 1.\n");
    exit(2);
}

try {
    $r = BackupService::create($dir, !isset($opts['no-uploads']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Backup selesai: ' . $r['path'] . ' (' . number_format($r['bytes'] / 1024, 1) . " KB)\n";
foreach ($r['tables'] as $t => $n) {
    echo '  ' . str_pad($t, 28) . $n . " baris\n";
}
echo '  foto: ' . $r['photos'] . "\n";
foreach (BackupService::prune($dir, $keep) as $old) {
    echo "Dihapus (melebihi --keep={$keep}): {$old}\n";
}
