<?php
declare(strict_types=1);

/**
 * Pulihkan dari arsip backup. DESTRUKTIF — seluruh tabel diganti isi backup.
 *
 *   php tools/restore.php --file=/path/hme-backup-20261104-020000.zip --yes
 *   php tools/restore.php --file=dump.sql --yes            hanya SQL
 *   php tools/restore.php --file=... --yes --no-uploads    jangan sentuh foto
 *
 * Tanpa --yes hanya menampilkan apa yang akan dilakukan. Setelah pulih, jalankan:
 *   php tools/check-integrity.php
 * Matikan akses pengguna (mode pemeliharaan atau hentikan web) selama pemulihan.
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
$file = $opts['file'] ?? null;
if (!is_string($file) || $file === '') {
    fwrite(STDERR, "Pemakaian: php tools/restore.php --file=<arsip.zip|dump.sql> --yes [--no-uploads]\n");
    exit(2);
}

$db = (string) Config::get('db.name') . ' @ ' . Config::get('db.host');
echo "Target database : {$db}\nBerkas          : {$file}\n";
if (!isset($opts['yes'])) {
    echo "\nSIMULASI: tidak ada yang diubah. Tambahkan --yes untuk benar-benar memulihkan (SEMUA data di database ini akan diganti).\n";
    exit(0);
}

try {
    $r = BackupService::restore($file, !isset($opts['no-uploads']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Pemulihan GAGAL: ' . $e->getMessage() . "\nDatabase mungkin setengah terpulihkan — ulangi dengan arsip yang benar sebelum membuka aplikasi.\n");
    exit(1);
}
echo "Pemulihan selesai: {$r['statements']} pernyataan SQL, {$r['photos']} foto.\n";
echo "Langkah berikut: php tools/check-integrity.php\n";
