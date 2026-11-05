<?php
declare(strict_types=1);

/**
 * Menjalankan seluruh tes:
 *   php tests/run.php                 semua (termasuk HTTP ke Apache lokal)
 *   php tests/run.php --no-http       tanpa tes HTTP
 *   php tests/run.php --only=CSRF     hanya tes yang namanya memuat kata itu
 *
 * Tes memakai database TERPISAH `hme_peminjaman_test` (dibuat ulang setiap kali dijalankan).
 * Database kerja `hme_peminjaman` tidak pernah disentuh.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tahan output agar sesi PHP bisa dimulai (headers belum "terkirim"); laporan ditulis ke STDOUT langsung.
ob_start();

$testStart = time();
$testLogDir = sys_get_temp_dir() . '/hme_test_logs';
@mkdir($testLogDir, 0775, true);

putenv('APP_ENV=testing');
putenv('APP_DEBUG=false');
putenv('DB_NAME=hme_peminjaman_test');
putenv('TRUST_PROXY=false');
// Kunci VAPID KHUSUS TES (tidak pernah dipakai di server sungguhan). Tes memakai transport palsu → tidak ada yang menembak layanan push.
putenv('VAPID_PUBLIC_KEY=BFaQq7XduxIfeH3MeWMlJz7jCTE0Gctrc8uDP40vAP81VV5ZrcQCgt0I6GI7J6igalbOnRF8gsJ2Of3f23_CVHs');
putenv('VAPID_PRIVATE_KEY=wqbvgX-e2bYWk7rq9t8kGxZFIwC7RpAUlJ702aUX6WE');
putenv('VAPID_SUBJECT=mailto:tes@hme.test');
putenv('MAIL_DRIVER=log');         // email uji ditulis ke <LOG_DIR>/mail.log, tidak pernah dikirim sungguhan
putenv('LOG_DIR=' . $testLogDir);   // log tes tidak mencampuri log aplikasi
$testUploadDir = sys_get_temp_dir() . '/hme_test_uploads';
$rrm = static function (string $d) use (&$rrm): void {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rrm($f) : @unlink($f); }
    @rmdir($d);
};
$rrm($testUploadDir);
putenv('UPLOAD_DIR=' . $testUploadDir);  // foto uji tidak masuk storage/uploads asli

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/harness.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

if (Config::get('db.name') !== 'hme_peminjaman_test') {
    fwrite(STDERR, "Ditolak: database tes harus bernama hme_peminjaman_test.\n");
    exit(2);
}

// Siapkan database tes dari nol lewat migrator yang sama dengan produksi.
$root = Database::connect(null, false);
$root->exec('DROP DATABASE IF EXISTS `hme_peminjaman_test`');
$root->exec('CREATE DATABASE `hme_peminjaman_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$mig = new Migrator(Database::connect('hme_peminjaman_test'), HME_ROOT . '/database/migrations');
$mig->migrate();
$mig->seed(HME_ROOT . '/database/seeds');

$args = array_slice($argv, 1);
$noHttp = in_array('--no-http', $args, true);
$filter = null;
foreach ($args as $a) {
    if (strpos($a, '--only=') === 0) {
        $filter = substr($a, 7);
    }
}

foreach (['core', 'security', 'database', 'auth', 'users', 'items', 'loans', 'pqa', 'reports', 'notify', 'sync', 'migration', 'integrity', 'backup', 'revisi_v2', 'revisi_v2_pqa', 'revisi_v2_push', 'revisi_v2_sw', 'revisi_v2_sched', 'revisi_v2_dash'] as $c) {
    require __DIR__ . "/cases/{$c}.php";
}
if (!$noHttp) {
    require __DIR__ . '/cases/http.php';
    require __DIR__ . '/e2e_lib.php';
    require __DIR__ . '/cases/e2e.php';
    require __DIR__ . '/cases/e2e_items.php';
    require __DIR__ . '/cases/e2e_loans.php';
    require __DIR__ . '/cases/e2e_pqa.php';
    require __DIR__ . '/cases/e2e_reports.php';
    require __DIR__ . '/cases/e2e_notify.php';
    require __DIR__ . '/cases/e2e_sync.php';
    require __DIR__ . '/cases/e2e_migration.php';
    require __DIR__ . '/cases/e2e_sweep.php';
    require __DIR__ . '/cases/e2e_ops.php';
    require __DIR__ . '/cases/e2e_v2.php';
    require __DIR__ . '/cases/e2e_v2_dash.php';
    TestServer::start($testLogDir);
}

try {
    $code = run_all($filter);
} finally {
    if (class_exists('TestServer', false)) {
        TestServer::stop();
    }
}

$rrm($testUploadDir);

// Bersihkan file sesi yang dibuat selama tes ini (agar storage/sessions tidak menumpuk).
foreach (glob(HME_ROOT . '/storage/sessions/sess_*') ?: [] as $f) {
    if (@filemtime($f) >= $testStart - 1) {
        @unlink($f);
    }
}
ob_end_clean();
exit($code);
