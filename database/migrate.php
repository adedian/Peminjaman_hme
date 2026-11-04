<?php
declare(strict_types=1);

/**
 * CLI migrasi database.
 *
 *   php database/migrate.php                  jalankan migrasi yang belum diterapkan
 *   php database/migrate.php --create-db      buat database bila belum ada, lalu migrasi
 *   php database/migrate.php --seed           jalankan juga seed data acuan
 *   php database/migrate.php status           tampilkan migrasi yang sudah diterapkan
 *   php database/migrate.php --fresh --yes    HAPUS lalu buat ulang database (hanya non-production)
 *
 * Database dipilih dari .env (DB_NAME) atau variabel lingkungan DB_NAME.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

$args = array_slice($argv, 1);
$has = static fn (string $f): bool => in_array($f, $args, true);

$dbName = (string) Config::get('db.name');
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "DB_NAME tidak valid.\n");
    exit(1);
}

try {
    if ($has('--fresh')) {
        if (Config::isProduction()) {
            fwrite(STDERR, "Ditolak: --fresh tidak boleh dijalankan di production.\n");
            exit(1);
        }
        if (!$has('--yes')) {
            fwrite(STDERR, "Ini akan MENGHAPUS database '{$dbName}'. Jalankan lagi dengan --yes untuk melanjutkan.\n");
            exit(1);
        }
        $root = Database::connect(null, false);
        $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        echo "Database '{$dbName}' dihapus.\n";
    }
    if ($has('--create-db') || $has('--fresh')) {
        $root = Database::connect(null, false);
        $root->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Database '{$dbName}' siap.\n";
    }

    $pdo = Database::connect($dbName);
    $m = new Migrator($pdo, __DIR__ . '/migrations');

    if (in_array('status', $args, true)) {
        $rows = $m->status();
        if ($rows === []) {
            echo "Belum ada migrasi yang diterapkan.\n";
        }
        foreach ($rows as $r) {
            echo str_pad($r['version'], 44) . $r['applied_at'] . "\n";
        }
        exit(0);
    }

    $res = $m->migrate();
    foreach ($res['applied'] as $v) {
        echo "  + {$v}\n";
    }
    echo count($res['applied']) . ' migrasi diterapkan, ' . count($res['skipped']) . " dilewati.\n";
    foreach ($res['modified'] as $v) {
        echo "  ! PERINGATAN: {$v} sudah diterapkan tetapi isi filenya berubah. Buat migrasi baru, jangan ubah yang lama.\n";
    }

    if ($has('--seed')) {
        $n = $m->seed(__DIR__ . '/seeds');
        echo "{$n} pernyataan seed dijalankan.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
