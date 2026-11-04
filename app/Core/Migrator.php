<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Menjalankan database/migrations/*.sql berurutan dan mencatatnya di schema_migrations.
 * - Idempoten: migrasi yang sudah diterapkan tidak dijalankan lagi.
 * - Checksum: bila file yang sudah diterapkan diubah, dilaporkan sebagai "modified" (tidak diterapkan ulang).
 * - Satu file boleh berisi banyak pernyataan dipisah ';' di akhir baris. Komentar baris '-- ' diabaikan.
 *   (DDL MySQL tidak bisa di-rollback; migrasi yang gagal di tengah harus diperbaiki manual lalu dijalankan ulang.)
 */
final class Migrator
{
    private PDO $pdo;
    private string $dir;

    public function __construct(PDO $pdo, string $migrationsDir)
    {
        $this->pdo = $pdo;
        $this->dir = rtrim($migrationsDir, '/\\');
    }

    /** @return array{applied:array<int,string>,skipped:array<int,string>,modified:array<int,string>} */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $done = [];
        foreach ($this->pdo->query('SELECT version, checksum FROM schema_migrations') as $row) {
            $done[$row['version']] = $row['checksum'];
        }

        $result = ['applied' => [], 'skipped' => [], 'modified' => []];
        foreach ($this->files($this->dir) as $file) {
            $version = basename($file, '.sql');
            $sql = (string) file_get_contents($file);
            $sum = hash('sha256', $sql);

            if (isset($done[$version])) {
                $result['skipped'][] = $version;
                if (!hash_equals($done[$version], $sum)) {
                    $result['modified'][] = $version;
                }
                continue;
            }
            foreach (self::statements($sql) as $stmt) {
                $this->pdo->exec($stmt);
            }
            $ins = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $ins->execute([$version, $sum]);
            $result['applied'][] = $version;
        }
        return $result;
    }

    /** Seed bersifat idempoten (INSERT ... ON DUPLICATE KEY); aman dijalankan berulang. */
    public function seed(string $seedsDir): int
    {
        $n = 0;
        foreach ($this->files(rtrim($seedsDir, '/\\')) as $file) {
            foreach (self::statements((string) file_get_contents($file)) as $stmt) {
                $this->pdo->exec($stmt);
                $n++;
            }
        }
        return $n;
    }

    /** @return array<int,array{version:string,applied_at:string}> */
    public function status(): array
    {
        try {
            return $this->pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,string> */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Folder tidak ditemukan: {$dir}");
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return array<int,string> */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $lines[] = $line;
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
