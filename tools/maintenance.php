<?php
declare(strict_types=1);

/**
 * Pembersihan berkala (jalankan harian lewat cron).
 *
 *   php tools/maintenance.php                 bersihkan
 *   php tools/maintenance.php --dry-run       hanya hitung, tidak menghapus
 *   php tools/maintenance.php --notif-days=90 --mail-days=90
 *   php tools/maintenance.php --audit-days=730   juga buang audit log lebih tua dari N hari (min 90; default: tidak pernah)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\MaintenanceService;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: {$a}\n");
        exit(2);
    }
}
$params = ['dry_run' => isset($opts['dry-run'])];
foreach (['notif-days' => 'notif_days', 'mail-days' => 'mail_days', 'audit-days' => 'audit_days'] as $flag => $key) {
    if (isset($opts[$flag])) {
        $params[$key] = (int) $opts[$flag];
    }
}

try {
    $r = MaintenanceService::run($params);
} catch (Throwable $e) {
    fwrite(STDERR, 'Pemeliharaan GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
echo ($params['dry_run'] ? "SIMULASI — yang akan dibuang:\n" : "Dibuang:\n");
foreach ($r as $k => $n) {
    echo '  ' . str_pad($k, 28) . $n . "\n";
}
