<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Backup & pemulihan (Tahap 8). Murni PHP + PDO — tidak membutuhkan mysqldump/shell,
 * sehingga jalan di shared hosting.
 *
 * Format dump: SATU pernyataan per baris (string di-escape sehingga tidak ada baris baru mentah),
 * hanya jenis pernyataan yang dikenal (SET, DROP TABLE, CREATE TABLE, INSERT INTO). Pemulihan menolak
 * baris di luar daftar itu, jadi berkas backup yang diubah orang lain tidak bisa menjalankan SQL sembarang.
 *
 * Arsip .zip berisi: database.sql, uploads/<foto>, manifest.json (versi, waktu, jumlah baris per tabel, sha256).
 */
final class BackupService
{
    private const BATCH_ROWS = 200;

    /** Membuat arsip backup; mengembalikan path arsip dan ringkasan. */
    public static function create(string $dir, bool $withUploads = true): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Folder backup tidak dapat dibuat: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Folder backup tidak dapat ditulis: {$dir}");
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi zip PHP tidak aktif.');
        }

        $stamp = date('Ymd-His');
        $sqlPath = $dir . "/.tmp-{$stamp}-" . bin2hex(random_bytes(4)) . '.sql';
        $zipPath = $dir . "/hme-backup-{$stamp}.zip";

        try {
            $tables = self::dumpDatabase($sqlPath);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Arsip backup tidak dapat dibuat.');
            }
            $zip->addFile($sqlPath, 'database.sql');
            $photos = 0;
            if ($withUploads) {
                $up = (string) Config::get('paths.uploads');
                foreach (self::uploadFiles($up) as $rel => $abs) {
                    $zip->addFile($abs, 'uploads/' . $rel);
                    $photos++;
                }
            }
            $manifest = [
                'app'        => 'peminjaman-hme',
                'created_at' => date('c'),
                'database'   => (string) Config::get('db.name'),
                'tables'     => $tables,
                'photos'     => $photos,
                'sql_sha256' => hash_file('sha256', $sqlPath),
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new RuntimeException('Arsip backup gagal ditulis (disk penuh?).');
            }
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw $e;
        } finally {
            @unlink($sqlPath);
        }
        @chmod($zipPath, 0640);

        return [
            'path'   => $zipPath,
            'bytes'  => (int) filesize($zipPath),
            'tables' => $tables,
            'photos' => $photos,
        ];
    }

    /** Hapus backup lama, sisakan $keep terbaru. Mengembalikan daftar yang dihapus. */
    public static function prune(string $dir, int $keep): array
    {
        $files = glob($dir . '/hme-backup-*.zip') ?: [];
        rsort($files); // nama memuat stempel waktu → urutan nama = urutan waktu
        $removed = [];
        foreach (array_slice($files, max(1, $keep)) as $f) {
            if (@unlink($f)) {
                $removed[] = basename($f);
            }
        }
        return $removed;
    }

    /**
     * Memulihkan dari arsip .zip atau .sql. DESTRUKTIF: tabel yang ada dihapus dan dibuat ulang.
     * @return array{statements:int, photos:int}
     */
    public static function restore(string $file, bool $withUploads = true): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Berkas tidak ditemukan: {$file}");
        }
        $tmpSql = null;
        $photos = 0;
        $zip = null;
        try {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($file) !== true) {
                    throw new RuntimeException('Arsip tidak dapat dibuka.');
                }
                $sql = $zip->getFromName('database.sql');
                $manifestRaw = $zip->getFromName('manifest.json');
                if ($sql === false) {
                    throw new RuntimeException('Arsip tidak berisi database.sql.');
                }
                if ($manifestRaw !== false) {
                    $m = json_decode($manifestRaw, true);
                    if (is_array($m) && isset($m['sql_sha256']) && !hash_equals((string) $m['sql_sha256'], hash('sha256', $sql))) {
                        throw new RuntimeException('Checksum database.sql tidak cocok — arsip rusak atau diubah.');
                    }
                }
                $tmpSql = tempnam(sys_get_temp_dir(), 'hme-restore-');
                file_put_contents($tmpSql, $sql);
                unset($sql);
                $sqlFile = $tmpSql;
            } else {
                $sqlFile = $file;
            }

            $statements = self::loadSql($sqlFile);

            if ($zip !== null && $withUploads) {
                $photos = self::restoreUploads($zip);
            }
            return ['statements' => $statements, 'photos' => $photos];
        } finally {
            if ($zip !== null) {
                $zip->close();
            }
            if ($tmpSql !== null) {
                @unlink($tmpSql);
            }
        }
    }

    // ------------------------------------------------------------------ dump

    /** @return array<string,int> jumlah baris per tabel */
    private static function dumpDatabase(string $sqlPath): array
    {
        $pdo = Database::connect((string) Config::get('db.name'));
        // Snapshot konsisten lintas tabel tanpa mengunci tulis aplikasi.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $out = fopen($sqlPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Berkas dump tidak dapat dibuat.');
        }
        $w = static function (string $line) use ($out): void {
            if (fwrite($out, $line . "\n") === false) {
                throw new RuntimeException('Gagal menulis dump (disk penuh?).');
            }
        };

        $counts = [];
        try {
            $w('-- Peminjaman HME backup ' . date('c'));
            $w("SET NAMES utf8mb4;");
            $w("SET FOREIGN_KEY_CHECKS=0;");
            $w("SET UNIQUE_CHECKS=0;");
            $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");

            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                $t = (string) $t;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                    continue;
                }
                $create = (string) $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM)[1];
                $w("DROP TABLE IF EXISTS `{$t}`;");
                $w(str_replace(["\r\n", "\n", "\r"], ' ', $create) . ';');

                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll() as $c) {
                    $cols[] = '`' . $c['Field'] . '`';
                }
                $colList = implode(',', $cols);

                $n = 0;
                $buf = [];
                $stmt = $pdo->query("SELECT * FROM `{$t}`");
                while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = self::literal($pdo, $v);
                    }
                    $buf[] = '(' . implode(',', $vals) . ')';
                    $n++;
                    if (count($buf) >= self::BATCH_ROWS) {
                        $w("INSERT INTO `{$t}` ({$colList}) VALUES " . implode(',', $buf) . ';');
                        $buf = [];
                    }
                }
                if ($buf) {
                    $w("INSERT INTO `{$t}` ({$colList}) VALUES " . implode(',', $buf) . ';');
                }
                $counts[$t] = $n;
            }
            $w("SET FOREIGN_KEY_CHECKS=1;");
        } finally {
            fclose($out);
            $pdo->exec('ROLLBACK');
        }
        return $counts;
    }

    private static function literal(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
            return '0x' . bin2hex($s);
        }
        return $pdo->quote($s);
    }

    // --------------------------------------------------------------- restore

    private static function loadSql(string $sqlFile): int
    {
        // Satu baris = satu pernyataan; driver menolak "a; b" di dalam satu baris (suntikan lewat berkas yang diubah).
        // Harus diberikan saat koneksi dibuat — setAttribute() sesudahnya tidak berefek.
        $pdo = Database::connect((string) Config::get('db.name'), true, [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        $in = fopen($sqlFile, 'rb');
        if ($in === false) {
            throw new RuntimeException('Berkas SQL tidak dapat dibaca.');
        }
        $allowed = '/^(SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS=[01]|UNIQUE_CHECKS=[01]|SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\')|DROP TABLE IF EXISTS `[A-Za-z0-9_]+`|CREATE TABLE `[A-Za-z0-9_]+` \(|INSERT INTO `[A-Za-z0-9_]+` \()/';
        $count = 0;
        $lineNo = 0;
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            while (($line = fgets($in)) !== false) {
                $lineNo++;
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                if (!preg_match($allowed, $line) || !str_ends_with($line, ';')) {
                    throw new RuntimeException("Baris {$lineNo} bukan pernyataan backup yang dikenal — pemulihan dihentikan.");
                }
                $pdo->exec(substr($line, 0, -1));
                $count++;
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } finally {
            fclose($in);
        }
        return $count;
    }

    private static function restoreUploads(ZipArchive $zip): int
    {
        $dest = (string) Config::get('paths.uploads');
        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new RuntimeException("Folder foto tidak dapat dibuat: {$dest}");
        }
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                continue;
            }
            $rel = substr($name, strlen('uploads/'));
            // Hanya gambar bernama aman, maksimal satu tingkat subfolder (items/…): tanpa traversal, tanpa berkas tersembunyi.
            if (!preg_match('#^(?:[A-Za-z0-9_-]+/)?[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*\.(?:jpe?g|png|webp)$#i', $rel)) {
                continue;
            }
            $target = $dest . '/' . $rel;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false && file_put_contents($target, $data) !== false) {
                $n++;
            }
        }
        return $n;
    }

    /** @return array<string,string> path relatif => path absolut */
    private static function uploadFiles(string $root): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getFilename() === '.gitkeep' || $f->getFilename() === '.htaccess') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($root, '/\\')) + 1));
            $files[$rel] = $f->getPathname();
        }
        ksort($files);
        return $files;
    }
}
