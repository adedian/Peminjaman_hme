<?php
declare(strict_types=1);

/**
 * Migrasi data spreadsheet lama (ekspor XLSX dari Google Sheets) ke database — Tahap 6.
 *
 *   php tools/migrate-legacy.php --source="C:\hme_legacy\data.xlsx" --dry-run      uji coba: semua dijalankan lalu dibatalkan
 *   php tools/migrate-legacy.php --source="C:\hme_legacy\data.xlsx"                migrasi sungguhan + rekonsiliasi
 *   php tools/migrate-legacy.php --source=... --download-photos                    + unduh foto dari Drive (folder harus dibagikan)
 *   php tools/migrate-legacy.php --source=... --reconcile-only                     hanya rekonsiliasi
 *   php tools/migrate-legacy.php --source=... --yes                                wajib di production (konfirmasi)
 *
 * Aman dijalankan ulang (idempoten): data yang sudah ada dilewati, tidak digandakan, tidak ditimpa.
 * Berkas ekspor berisi password lama → WAJIB disimpan di luar folder aplikasi; skrip menolak berkas di dalamnya.
 * Laporan per baris tersedia di halaman admin "Laporan Migrasi".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Migration\LegacyImporter;
use App\Migration\LegacySource;
use App\Migration\Reconciler;

$opts = getopt('', ['source:', 'dry-run', 'download-photos', 'reconcile-only', 'yes', 'json']);
$out = static function (string $s): void { fwrite(STDOUT, $s . "\n"); };
$err = static function (string $s): void { fwrite(STDERR, $s . "\n"); };

$source = isset($opts['source']) ? (string) $opts['source'] : '';
if ($source === '') {
    $err('Gunakan: php tools/migrate-legacy.php --source="<path ekspor .xlsx>" [--dry-run] [--download-photos] [--reconcile-only] [--yes]');
    exit(2);
}
$real = realpath($source);
if ($real === false) {
    $err("Berkas tidak ditemukan: {$source}");
    exit(2);
}
$norm = static fn(string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
if (strpos($norm($real) . '/', $norm(HME_ROOT) . '/') === 0) {
    $err('Ditolak: berkas ekspor berisi password lama dan tidak boleh disimpan di dalam folder aplikasi. Pindahkan ke luar ' . HME_ROOT . '.');
    exit(2);
}
$dry = isset($opts['dry-run']);
if (!$dry && !isset($opts['reconcile-only']) && Config::isProduction() && !isset($opts['yes'])) {
    $err('Production: tambahkan --yes untuk menjalankan migrasi sungguhan (jalankan --dry-run dulu).');
    exit(2);
}

try {
    $src = new LegacySource($real);
} catch (Throwable $e) {
    $err('Sumber tidak dapat dibaca: ' . $e->getMessage());
    exit(2);
}
$out('Sumber : ' . basename($real));
$out('SHA-256: ' . $src->sha256());
$out('Database: ' . Config::get('db.name') . ($dry ? '  (DRY-RUN — tidak ada data yang disimpan)' : ''));

$exit = 0;
if (!isset($opts['reconcile-only'])) {
    $sum = LegacyImporter::run($src, $dry, ['download_photos' => isset($opts['download-photos'])]);
    $out('');
    $out(sprintf('Run #%d (%s): %s', $sum['run_id'], $sum['mode'], strtoupper($sum['status'])));
    if ($sum['error'] !== null) {
        $err('Kesalahan: ' . $sum['error']);
    }
    foreach ($sum['stats'] as $k => $v) {
        $out(sprintf('  %-40s %d', $k, $v));
    }
    $out(sprintf('Per baris: ok %d · gagal %d · duplikat %d · perlu diperiksa %d',
        $sum['counts']['ok'], $sum['counts']['failed'], $sum['counts']['duplicate'], $sum['counts']['needs_review']));
    if ($sum['status'] !== 'success') {
        exit(1);
    }
    if ($dry) {
        $out('Dry-run selesai. Lihat rincian per baris di halaman admin "Laporan Migrasi".');
        exit($sum['counts']['failed'] > 0 ? 1 : 0);
    }
}

$rec = Reconciler::run($src);
$out('');
$out('REKONSILIASI (sumber vs database)');
foreach ($rec['checks'] as $c) {
    $out(sprintf('  [%s] %-9s %s', $c['ok'] ? ' OK ' : 'GAGAL', $c['group'], $c['label']));
    if (!$c['ok'] || (is_string($c['expected']) && strlen($c['expected']) < 120)) {
        $out('           sumber: ' . (is_scalar($c['expected']) ? (string) $c['expected'] : json_encode($c['expected'])));
        $out('           sistem: ' . (is_scalar($c['actual']) ? (string) $c['actual'] : json_encode($c['actual'])));
    }
    foreach ($c['details'] as $d) {
        $out('           - ' . $d);
    }
}
$out($rec['ok'] ? 'Rekonsiliasi: SEMUA COCOK.' : "Rekonsiliasi: {$rec['failed']} pemeriksaan GAGAL.");

// Simpan hasil rekonsiliasi pada run nyata terakhir untuk sumber yang sama (ditampilkan di halaman admin)
$runId = Database::value("SELECT id FROM migration_runs WHERE mode = 'real' AND source_sha256 = ? ORDER BY id DESC LIMIT 1", [$src->sha256()]);
if ($runId !== null) {
    $summary = json_decode((string) Database::value('SELECT summary_json FROM migration_runs WHERE id = ?', [$runId]), true) ?: [];
    $summary['reconciliation'] = ['ok' => $rec['ok'], 'failed' => $rec['failed'], 'checked_at' => date('Y-m-d H:i:s'), 'checks' => $rec['checks']];
    Database::run('UPDATE migration_runs SET summary_json = ? WHERE id = ?', [json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
}
exit($rec['ok'] ? $exit : 1);
