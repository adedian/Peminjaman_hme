<?php
declare(strict_types=1);

/**
 * Mode pemeliharaan: semua halaman & API menjawab 503 (kecuali /api/v1/health). Pakai saat upgrade / restore backup.
 *
 *   php tools/maintenance-mode.php on
 *   php tools/maintenance-mode.php off
 *   php tools/maintenance-mode.php status
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$flag = dirname(__DIR__) . '/storage/maintenance.flag';
$cmd = $argv[1] ?? 'status';

switch ($cmd) {
    case 'on':
        if (file_put_contents($flag, date('c') . "\n") === false) {
            fwrite(STDERR, "Gagal menulis {$flag} (izin folder storage/?).\n");
            exit(1);
        }
        echo "Mode pemeliharaan AKTIF — pengguna melihat halaman 503.\n";
        break;
    case 'off':
        if (is_file($flag) && !@unlink($flag)) {
            fwrite(STDERR, "Gagal menghapus {$flag}.\n");
            exit(1);
        }
        echo "Mode pemeliharaan nonaktif.\n";
        break;
    case 'status':
        echo is_file($flag) ? 'AKTIF sejak ' . trim((string) file_get_contents($flag)) . "\n" : "Nonaktif.\n";
        break;
    default:
        fwrite(STDERR, "Pemakaian: php tools/maintenance-mode.php on|off|status\n");
        exit(2);
}
