<?php
declare(strict_types=1);

/**
 * Pemeriksa lingkungan — jalankan setelah instalasi / deployment:
 *   php tools/check-env.php
 * Keluar dengan kode 1 bila ada pemeriksaan WAJIB yang gagal.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

$fail = 0;
$warn = 0;
$line = static function (string $status, string $label, string $detail = '') use (&$fail, &$warn): void {
    $icon = ['ok' => '[ OK ]', 'fail' => '[GAGAL]', 'warn' => '[PERINGATAN]'][$status];
    if ($status === 'fail') { $fail++; }
    if ($status === 'warn') { $warn++; }
    echo str_pad($icon, 13) . str_pad($label, 38) . $detail . "\n";
};

echo "Pemeriksaan lingkungan Peminjaman HME\n" . str_repeat('-', 78) . "\n";

// PHP
version_compare(PHP_VERSION, '8.0.0', '>=')
    ? $line('ok', 'Versi PHP >= 8.0', PHP_VERSION)
    : $line('fail', 'Versi PHP >= 8.0', 'terpasang ' . PHP_VERSION);

foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'zip', 'openssl', 'json', 'ctype', 'xml', 'xmlreader'] as $ext) {
    extension_loaded($ext) ? $line('ok', "Ekstensi {$ext}") : $line('fail', "Ekstensi {$ext}", 'tidak aktif — aktifkan di php.ini');
}

// Folder tulis
foreach (['logs', 'sessions', 'uploads', 'cache', 'imports', 'exports'] as $d) {
    $p = HME_ROOT . '/storage/' . $d;
    if (!is_dir($p)) { @mkdir($p, 0775, true); }
    is_dir($p) && is_writable($p) ? $line('ok', "storage/{$d} dapat ditulis") : $line('fail', "storage/{$d} dapat ditulis", 'periksa izin folder');
}

// Konfigurasi
is_file(HME_ROOT . '/.env')
    ? $line('ok', 'File .env ada')
    : $line('warn', 'File .env ada', 'tidak ada — memakai default production');
$env = (string) Config::get('app.env');
$line('ok', 'APP_ENV', $env);
if ($env === 'production') {
    Config::get('app.debug') ? $line('warn', 'APP_DEBUG di production', 'sebaiknya false (diabaikan otomatis)') : $line('ok', 'APP_DEBUG nonaktif');
    is_dir(HME_ROOT . '/design') ? $line('warn', 'Folder design/', 'hapus dari server production (hanya acuan desain)') : $line('ok', 'Folder design/ tidak ada');
    ((string) Config::get('db.user')) === 'root' ? $line('warn', 'DB_USER', 'jangan pakai root di production; buat user khusus') : $line('ok', 'DB_USER bukan root');
    ((string) Config::get('db.pass')) === '' ? $line('warn', 'DB_PASS kosong', 'wajib diisi di production') : $line('ok', 'DB_PASS terisi');
}

// Database
try {
    $pdo = Database::connect((string) Config::get('db.name'));
    $line('ok', 'Koneksi database', (string) Config::get('db.name') . ' @ ' . Config::get('db.host'));
    $files = glob(HME_ROOT . '/database/migrations/*.sql') ?: [];
    $applied = count((new Migrator($pdo, HME_ROOT . '/database/migrations'))->status());
    $applied === count($files)
        ? $line('ok', 'Migrasi database', "{$applied}/" . count($files) . ' diterapkan')
        : $line('fail', 'Migrasi database', "{$applied}/" . count($files) . ' — jalankan: php database/migrate.php');
    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
    $admins > 0 ? $line('ok', 'Admin aktif', (string) $admins) : $line('warn', 'Admin aktif', 'belum ada — buat dengan: php tools/create-admin.php --username=... --name="..."');
} catch (Throwable $e) {
    $line('fail', 'Koneksi database', 'gagal: periksa DB_* di .env');
}

echo str_repeat('-', 78) . "\n";
echo $fail === 0 ? "Selesai: siap digunakan" : "Selesai: {$fail} pemeriksaan wajib gagal";
echo $warn > 0 ? " ({$warn} peringatan)\n" : "\n";
exit($fail === 0 ? 0 : 1);
