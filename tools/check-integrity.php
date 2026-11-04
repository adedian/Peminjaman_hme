<?php
declare(strict_types=1);

/**
 * Pemeriksaan konsistensi data (hanya membaca).
 *   php tools/check-integrity.php          → ringkasan; exit 1 bila ada pelanggaran
 * Disarankan dijalankan setelah migrasi/pemulihan backup dan berkala (cron mingguan) di production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\IntegrityService;

$r = IntegrityService::check();
fwrite(STDOUT, 'Database: ' . Config::get('db.name') . "\n");
foreach ($r['checks'] as $c) {
    fwrite(STDOUT, sprintf("  [%s] %s\n", $c['violations'] === 0 ? ' OK ' : 'GAGAL', $c['rule']));
    if ($c['violations'] > 0) {
        fwrite(STDOUT, '         ' . $c['violations'] . ' pelanggaran, contoh: ' . implode(', ', $c['samples']) . "\n");
    }
}
fwrite(STDOUT, $r['ok'] ? "Data konsisten.\n" : "Ada data yang tidak konsisten.\n");
exit($r['ok'] ? 0 : 1);
