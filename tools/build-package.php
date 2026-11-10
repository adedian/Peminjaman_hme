<?php
declare(strict_types=1);

/**
 * Membangun paket deployment (.zip) yang bersih untuk diunggah ke server.
 *
 *   php tools/build-package.php                  → dist/hme-peminjaman-<versi>.zip
 *   php tools/build-package.php --out=/tmp/rilis
 *
 * Isi paket : app, database, public, routes, tools, deploy, storage (kerangka kosong), vendor, composer.*, .env.example, docs.
 * Dikecualikan: .env, .git, tests/, design/, _legacy/, dist/, .htaccess root (khusus XAMPP), isi storage/* (log, sesi, foto, backup),
 *               docs/test-reports.
 * Prasyarat : `composer install --no-dev --optimize-autoloader` sudah dijalankan (vendor/ lengkap).
 * Paket menyertakan MANIFEST.sha256 untuk memverifikasi unggahan: `cd <folder> && sha256sum -c MANIFEST.sha256`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: {$a}\n");
        exit(2);
    }
}
$outDir = is_string($opts['out'] ?? null) ? rtrim((string) $opts['out'], '/\\') : $root . '/dist';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Ekstensi zip PHP tidak aktif.\n");
    exit(1);
}
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/ belum ada. Jalankan: composer install --no-dev --optimize-autoloader\n");
    exit(1);
}
if (is_dir($root . '/vendor/phpunit') || is_dir($root . '/vendor/bin/phpunit')) {
    fwrite(STDERR, "vendor/ memuat paket dev. Jalankan: composer install --no-dev --optimize-autoloader\n");
    exit(1);
}

$includeDirs = ['app', 'database', 'public', 'routes', 'tools', 'deploy', 'vendor', 'docs'];
$includeFiles = ['composer.json', 'composer.lock', '.env.example'];
$skipRel = static function (string $rel): bool {
    return str_starts_with($rel, 'docs/test-reports')
        // Dokumen perencanaan internal memuat email/nomor kontak asli → tidak perlu ada di server
        || preg_match('#^docs/(01|14|15)-#', $rel) === 1
        || preg_match('#(^|/)\.github(/|$)#', $rel) === 1
        || preg_match('#(^|/)\.(git|svn|idea|vscode)(/|$)#', $rel) === 1
        || preg_match('#(^|/)\.env$#', $rel) === 1
        || preg_match('#\.(log|bak|swp)$#', $rel) === 1
        || str_starts_with($rel, 'vendor/bin/');
};

$files = [];
foreach ($includeDirs as $d) {
    if (!is_dir("{$root}/{$d}")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$d}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (!$skipRel($rel)) {
            $files[$rel] = $f->getPathname();
        }
    }
}
foreach ($includeFiles as $f) {
    if (is_file("{$root}/{$f}")) {
        $files[$f] = "{$root}/{$f}";
    }
}
// Kerangka storage: folder + .htaccess + .gitkeep saja (tanpa data).
foreach (['storage/.htaccess'] as $f) {
    if (is_file("{$root}/{$f}")) {
        $files[$f] = "{$root}/{$f}";
    }
}
$skeleton = ['logs', 'sessions', 'uploads', 'cache', 'imports', 'exports', 'backups'];

ksort($files);

// Versi: tanggal + git (bila ada) + isi migrasi terakhir
$version = date('Ymd-His');
$head = @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null');
if (is_string($head) && trim($head) !== '') {
    $version .= '-' . trim($head);
}

if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Folder keluaran tidak dapat dibuat: {$outDir}\n");
    exit(1);
}
$zipPath = "{$outDir}/hme-peminjaman-{$version}.zip";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Arsip tidak dapat dibuat.\n");
    exit(1);
}
$prefix = 'hme-peminjaman/';
$manifest = '';
foreach ($files as $rel => $abs) {
    $zip->addFile($abs, $prefix . $rel);
    $manifest .= hash_file('sha256', $abs) . '  ' . $rel . "\n";
}
foreach ($skeleton as $d) {
    $zip->addEmptyDir($prefix . 'storage/' . $d);
    $zip->addFromString($prefix . "storage/{$d}/.gitkeep", '');
}
$zip->addFromString($prefix . 'VERSION', $version . "\n");
$zip->addFromString($prefix . 'MANIFEST.sha256', $manifest);
if (!$zip->close()) {
    fwrite(STDERR, "Arsip gagal ditulis.\n");
    exit(1);
}

printf("Paket siap: %s\n  %d berkas, %.1f MB, versi %s\n", $zipPath, count($files), filesize($zipPath) / 1048576, $version);
printf("  SHA-256 arsip: %s\n", hash_file('sha256', $zipPath));
echo "Verifikasi di server setelah ekstrak: cd hme-peminjaman && sha256sum -c MANIFEST.sha256 --quiet\n";
