<?php
declare(strict_types=1);

/**
 * Membuat pasangan kunci VAPID untuk notifikasi push (sekali saja per server).
 *
 *   php tools/generate-vapid.php            tampilkan tiga baris untuk .env
 *   php tools/generate-vapid.php --write    tambahkan ke .env bila VAPID_* belum terisi (tidak menimpa nilai yang ada)
 *
 * PERINGATAN: kunci privat bersifat rahasia (jangan dibagikan/di-commit). Mengganti kunci di kemudian hari membuat
 * SEMUA perangkat harus mengaktifkan notifikasi ulang, karena langganan terikat pada kunci publik server.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\WebPush;

$write = in_array('--write', array_slice($argv, 1), true);
$envFile = dirname(__DIR__) . '/.env';

try {
    if ($write) {
        $cur = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        if (preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $cur) || preg_match('/^VAPID_PUBLIC_KEY=\S+/m', $cur)) {
            fwrite(STDERR, "Ditolak: VAPID_* di .env sudah terisi. Hapus barisnya dulu bila memang ingin mengganti kunci (semua perangkat harus berlangganan ulang).\n");
            exit(1);
        }
    }
    $k = WebPush::generateVapidKeys();
    $lines = "VAPID_PUBLIC_KEY={$k['public']}\nVAPID_PRIVATE_KEY={$k['private']}\nVAPID_SUBJECT=mailto:admin@perusahaan.co.id\n";
    if ($write) {
        $cur = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        $cur = preg_replace('/^VAPID_(PUBLIC_KEY|PRIVATE_KEY|SUBJECT)=.*\R?/m', '', $cur) ?? $cur;
        file_put_contents($envFile, rtrim($cur) . "\n\n# Notifikasi push (dibuat oleh tools/generate-vapid.php)\n" . $lines);
        echo "Kunci VAPID ditulis ke .env. Ganti VAPID_SUBJECT dengan email kontak Anda.\n";
    } else {
        echo $lines;
        echo "\n(Salin ke .env — kunci privat bersifat rahasia.)\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
