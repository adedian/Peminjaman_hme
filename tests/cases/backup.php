<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Services\BackupService;
use App\Services\IntegrityService;
use App\Services\LoanService;
use App\Services\MaintenanceService;

function bkTree(string $d): array
{
    $r = [];
    foreach (glob($d . '/*') ?: [] as $f) {
        is_dir($f) ? $r = array_merge($r, bkTree($f)) : $r[] = $f;
    }
    return $r;
}

function bkRm(string $d): void
{
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) { continue; }
        is_dir($f) ? bkRm($f) : @unlink($f);
    }
    @rmdir($d);
}

function bkSnapshot(): array
{
    $snap = [];
    foreach (Database::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $rows = Database::pdo()->query("SELECT * FROM `{$t}` ORDER BY 1")->fetchAll(PDO::FETCH_NUM);
        $snap[$t] = md5(json_encode($rows));
    }
    return $snap;
}

// ---------------------------------------------------------------------------
section('Tahap 8 — backup & pemulihan');

t('Backup → ubah/hapus data → pulihkan: semua tabel & foto kembali persis; karakter khusus & NULL selamat; integritas lulus', function () {
    bkWithUploadDir(fn() => mgWithDatabase('hme_peminjaman_test_bk', function () {
        $dir = sys_get_temp_dir() . '/hme_bk_' . bin2hex(random_bytes(3));
        $up = ImageUploadDir();
        $adm = ['id' => aUser('bk_admin', 'Admin-Uji-123', 'admin'), 'role' => 'admin', 'name' => 'Bk Admin'];
        $s = ['id' => aUser('bk_staff'), 'role' => 'staff', 'name' => 'Bk Staff', 'username' => 'bk_staff'];
        $a = iMake(['name' => "Barang \"kutip\" 'apos' \\ garis\nbaru — 日本語 😀"])['id'];
        $b = iMake(['name' => 'Barang B'])['id'];
        $r1 = LoanService::submit($s, ['item_ids' => [$a, $b], 'due_at' => dueAt(), 'location' => "Lokasi;DROP TABLE users; --\r\nbaris2", 'note' => null], ureq());
        LoanService::approveRequest($r1['id'], $adm, ureq());
        Database::run("INSERT INTO settings (key_name, value) VALUES ('bk_bin', ?)", ["a'b\\c\n;--"]);

        @mkdir($up . '/items', 0775, true);
        file_put_contents($up . '/items/' . str_repeat('a', 32) . '.jpg', "\xFF\xD8\xFF\xE0bk-photo-1");
        file_put_contents($up . '/items/' . str_repeat('a', 32) . '_t.jpg', "\xFF\xD8\xFF\xE0bk-thumb-1");
        $before = bkSnapshot();

        $r = BackupService::create($dir, true);
        ok(is_file($r['path']) && $r['bytes'] > 0);
        eq(2, $r['photos']);
        ok($r['tables']['users'] >= 2 && $r['tables']['loans'] === 2);

        $zip = new ZipArchive();
        $zip->open($r['path']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
        ok(in_array('database.sql', $names, true) && in_array('manifest.json', $names, true));
        ok(!in_array('.env', $names, true), 'arsip tidak boleh memuat .env');
        $sql = (string) $zip->getFromName('database.sql');
        $zip->close();
        // Format: tidak ada baris baru mentah di dalam pernyataan → tiap baris = pernyataan utuh atau komentar
        foreach (explode("\n", rtrim($sql, "\n")) as $line) {
            ok($line === '' || str_starts_with($line, '--') || str_ends_with($line, ';'), 'baris dump utuh: ' . substr($line, 0, 60));
        }

        // Rusakkan: hapus & ubah data, hapus foto
        Database::pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        Database::run('DELETE FROM loans');
        Database::run('UPDATE items SET name = ?', ['RUSAK']);
        Database::run("DELETE FROM settings WHERE key_name = 'bk_bin'");
        Database::pdo()->exec('DROP TABLE mail_outbox');
        Database::pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        bkRm($up . '/items');
        ok(bkSnapshot() !== $before, 'kerusakan memang terjadi sebelum pemulihan');

        $res = BackupService::restore($r['path'], true);
        ok($res['statements'] > 20);
        eq(2, $res['photos']);
        Database::disconnect();
        eq($before, bkSnapshot(), 'setiap tabel identik dengan sebelum backup');
        eq("a'b\\c\n;--", Database::value("SELECT value FROM settings WHERE key_name = 'bk_bin'"));
        eq("Lokasi;DROP TABLE users; --\r\nbaris2", Database::value('SELECT location FROM loans ORDER BY id LIMIT 1'));
        eq("\xFF\xD8\xFF\xE0bk-photo-1", file_get_contents($up . '/items/' . str_repeat('a', 32) . '.jpg'));
        $integ = IntegrityService::check();
        ok($integ['ok'], json_encode(array_values(array_filter($integ['checks'], fn($x) => $x['violations'] > 0))));

        // CLI end-to-end (proses terpisah, membaca DB yang sedang dipakai tes)
        $php = escapeshellarg(PHP_BINARY);
        exec($php . ' ' . escapeshellarg(HME_ROOT . '/tools/backup.php') . ' --dir=' . escapeshellarg($dir . '/cli') . ' --keep=1 2>&1', $o1, $c1);
        eq(0, $c1, implode("\n", $o1));
        exec($php . ' ' . escapeshellarg(HME_ROOT . '/tools/restore.php') . ' --file=' . escapeshellarg($r['path']) . ' 2>&1', $o2, $c2);
        eq(0, $c2);
        contains('SIMULASI', implode("\n", $o2));
        eq($before, bkSnapshot(), 'tanpa --yes tidak mengubah apa pun');

        bkRm($dir);
    }));
});

t('Arsip/SQL yang diubah atau berbahaya ditolak: checksum, pernyataan asing, multi-statement, zip-slip, berkas tersembunyi', function () {
    bkWithUploadDir(fn() => mgWithDatabase('hme_peminjaman_test_bk2', function () {
        $dir = sys_get_temp_dir() . '/hme_bk2_' . bin2hex(random_bytes(3));
        $up = ImageUploadDir();
        $r = BackupService::create($dir, false);
        $before = bkSnapshot();

        // 1. isi database.sql diubah tanpa memperbarui manifest
        $z = new ZipArchive();
        $z->open($r['path']);
        $z->addFromString('database.sql', $z->getFromName('database.sql') . "DROP DATABASE hme_peminjaman_test_bk2;\n");
        $z->close();
        $e = throws(fn() => BackupService::restore($r['path']), RuntimeException::class);
        contains('Checksum', $e->getMessage());

        // 2. SQL polos dengan pernyataan asing / multi-statement
        $bad = $dir . '/evil.sql';
        foreach ([
            "DROP DATABASE hme_peminjaman_test_bk2;\n",
            "INSERT INTO `settings` (`key_name`,`value`) VALUES ('x','y'); DROP TABLE `users`;\n",
            "INSERT INTO `settings` (`key_name`,`value`) VALUES ('x','y')\n",
            "GRANT ALL ON *.* TO 'x'@'%';\n",
            "SET GLOBAL general_log=1;\n",
        ] as $line) {
            file_put_contents($bad, "SET NAMES utf8mb4;\n" . $line);
            throws(fn() => BackupService::restore($bad), Throwable::class);
            eq($before, bkSnapshot(), 'database utuh setelah ditolak: ' . trim($line));
        }

        // 3. zip-slip & berkas tersembunyi/non-gambar tidak diekstrak
        $zipPath = $dir . '/slip.zip';
        $z = new ZipArchive();
        $z->open($zipPath, ZipArchive::CREATE);
        $sql = "SET NAMES utf8mb4;\n";
        $z->addFromString('database.sql', $sql);
        $z->addFromString('manifest.json', json_encode(['sql_sha256' => hash('sha256', $sql)]));
        $z->addFromString('uploads/../../evil.jpg', 'x');
        $z->addFromString('uploads/items/../../evil2.jpg', 'x');
        $z->addFromString('uploads/.htaccess', 'x');
        $z->addFromString('uploads/items/shell.php', '<?php');
        $z->addFromString('uploads/items/ok.jpg', 'x');
        $z->close();
        $res = BackupService::restore($zipPath, true);
        eq(1, $res['photos']);
        ok(is_file($up . '/items/ok.jpg'));
        ok(!is_file($up . '/items/shell.php') && !is_file($up . '/.htaccess') && !is_file(dirname($up) . '/evil.jpg'));

        throws(fn() => BackupService::restore($dir . '/tidak-ada.zip'), RuntimeException::class);
        bkRm($dir);
    }));
});

t('Retensi backup: --keep menyisakan yang terbaru saja', function () {
    $dir = sys_get_temp_dir() . '/hme_bk3_' . bin2hex(random_bytes(3));
    mkdir($dir, 0775, true);
    foreach (['20260101-010101', '20260102-010101', '20260103-010101', '20260104-010101'] as $s) {
        file_put_contents("{$dir}/hme-backup-{$s}.zip", 'x');
    }
    file_put_contents("{$dir}/catatan.txt", 'jangan dihapus');
    $removed = BackupService::prune($dir, 2);
    eq(['hme-backup-20260102-010101.zip', 'hme-backup-20260101-010101.zip'], $removed);
    ok(is_file("{$dir}/hme-backup-20260104-010101.zip") && is_file("{$dir}/hme-backup-20260103-010101.zip") && is_file("{$dir}/catatan.txt"));
    eq([], BackupService::prune($dir, 0 + 5));
    bkRm($dir);
});

// ---------------------------------------------------------------------------
section('Tahap 8 — pemeliharaan');

t('Pembersihan: hanya data usang yang dibuang; dry-run menghitung tanpa menghapus; data bisnis & audit utuh', function () {
    $uid = aUser('mt_staff');
    $old = date('Y-m-d H:i:s', strtotime('-200 days'));
    $mid = date('Y-m-d H:i:s', strtotime('-100 days'));
    $new = date('Y-m-d H:i:s', strtotime('-1 days'));
    $n = fn(string $read, string $created) => Database::run('INSERT INTO notifications (user_id, type, title, read_at, created_at) VALUES (?,?,?,?,?)', [$uid, 'x', 't', $read ?: null, $created]);
    $n($mid, $mid);   // dibaca, 100 hari  → dibuang
    $n($new, $mid);   // dibaca baru       → tetap (read_at baru)
    $n('', $mid);     // belum dibaca 100h → tetap (batas 180)
    $n('', $old);     // belum dibaca 200h → dibuang
    $n('', $new);     // baru              → tetap
    $mail = fn(string $st, string $created) => Database::run("INSERT INTO mail_outbox (recipients, subject, html_body, status, created_at) VALUES ('a@b.c','s','b',?,?)", [$st, $created]);
    $mail('sent', $mid); $mail('sent', $new); $mail('failed', $mid); $mail('failed', $old); $mail('pending', $old);
    Database::run("INSERT INTO change_events (entity_type, entity_id, action, created_at) VALUES ('x','1','a', NOW() - INTERVAL 5 DAY), ('x','2','a', NOW())");
    Database::run("INSERT INTO login_attempts (username, ip, success, created_at) VALUES ('u','1.1.1.1',0, NOW() - INTERVAL 3 DAY), ('u','1.1.1.1',0, NOW())");
    Database::run("INSERT INTO activity_logs (action, created_at) VALUES ('mt.old', NOW() - INTERVAL 900 DAY)");
    $items = (int) Database::value('SELECT COUNT(*) FROM items');
    $users = (int) Database::value('SELECT COUNT(*) FROM users');

    $stor = (string) Config::get('paths.storage');
    $sess = $stor . '/sessions/sess_mttest_old';
    $fresh = $stor . '/sessions/sess_mttest_fresh';
    file_put_contents($sess, 'x'); touch($sess, time() - 5 * 86400);
    file_put_contents($fresh, 'x');
    $keepFile = $stor . '/sessions/.gitkeep';
    $hadKeep = is_file($keepFile);

    $dry = MaintenanceService::run(['dry_run' => true]);
    eq(1, $dry['notifikasi dibaca']); eq(1, $dry['notifikasi lama']);
    eq(1, $dry['email terkirim/dilewati']); eq(1, $dry['email gagal']);
    eq(1, $dry['feed perubahan']); eq(1, $dry['percobaan login']);
    ok($dry['berkas sesi'] >= 1 && is_file($sess), 'dry-run tidak menghapus berkas');
    eq(5, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$uid]));
    ok(!isset($dry['audit log']), 'audit log tidak disentuh tanpa --audit-days');

    $real = MaintenanceService::run([]);
    eq($dry['notifikasi dibaca'], $real['notifikasi dibaca']);
    eq(3, (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$uid]));
    eq(3, (int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE recipients = 'a@b.c'"));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM change_events WHERE entity_type = 'x'"));
    ok(!is_file($sess) && is_file($fresh), 'sesi basi dibuang, sesi aktif tetap');
    eq($hadKeep, is_file($keepFile));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'mt.old'"), 'audit log lama tetap');
    eq($items, (int) Database::value('SELECT COUNT(*) FROM items'));
    eq($users, (int) Database::value('SELECT COUNT(*) FROM users'));

    $r3 = MaintenanceService::run(['audit_days' => 400]);
    eq(1, $r3['audit log']);
    eq(0, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'mt.old'"));
    $min = MaintenanceService::run(['audit_days' => 1, 'dry_run' => true]);   // batas minimum 90 hari
    ok(isset($min['audit log']));
    @unlink($fresh);
    Database::run('DELETE FROM notifications WHERE user_id = ?', [$uid]);
    Database::run("DELETE FROM mail_outbox WHERE recipients = 'a@b.c'");
});

function ImageUploadDir(): string
{
    return rtrim((string) Config::get('paths.uploads'), '/\\');
}

/** Jalankan $fn dengan folder foto sementara sendiri agar tes tidak memakai/menghapus foto tes lain. */
function bkWithUploadDir(callable $fn): void
{
    $prev = (string) getenv('UPLOAD_DIR');
    $tmp = sys_get_temp_dir() . '/hme_bk_up_' . bin2hex(random_bytes(3));
    mkdir($tmp, 0775, true);
    putenv('UPLOAD_DIR=' . $tmp);
    Config::load();
    try {
        $fn();
    } finally {
        putenv('UPLOAD_DIR=' . $prev);
        Config::load();
        bkRm($tmp);
    }
}
