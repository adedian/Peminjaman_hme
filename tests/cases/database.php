<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Migrator;

/** Kode error MySQL: 1062 = duplikat unique, 1451/1452 = pelanggaran foreign key, 1406 = data terlalu panjang. */
const E_DUP = 1062;

$GLOBALS['__seq'] = 5000;

/** @return array{user:int,user2:int,batch:int,cat:int} fondasi data uji (dibuat sekali) */
function dbBase(): array
{
    static $b = null;
    if ($b === null) {
        $h = password_hash('x', PASSWORD_DEFAULT);
        $u1 = Database::insert('users', ['username' => 'd_user1', 'password_hash' => $h, 'name' => 'U1', 'initials' => 'U1']);
        $u2 = Database::insert('users', ['username' => 'd_user2', 'password_hash' => $h, 'name' => 'U2', 'initials' => 'U2']);
        $batch = Database::insert('loan_batches', ['user_id' => $u1]);
        $cat = (int) Database::value('SELECT id FROM categories WHERE name = ?', ['Alat Ukur']);
        $b = ['user' => $u1, 'user2' => $u2, 'batch' => $batch, 'cat' => $cat];
    }
    return $b;
}

function mkItem(string $code, bool $pqa = false): int
{
    return Database::insert('items', ['code' => $code, 'name' => "Barang {$code}", 'category_id' => dbBase()['cat'], 'is_pqa' => $pqa ? 1 : 0]);
}

function mkLoan(int $item, string $status = 'pending', ?string $legacyId = null): int
{
    $b = dbBase();
    return Database::insert('loans', [
        'legacy_id' => $legacyId, 'seq_no' => ++$GLOBALS['__seq'], 'batch_id' => $b['batch'], 'user_id' => $b['user'], 'item_id' => $item,
        'item_name_snapshot' => 'X', 'carrier_name_snapshot' => 'Y', 'borrowed_at' => '2026-11-04 08:00:00',
        'location' => 'Lokasi uji', 'status' => $status,
    ]);
}

function mkPqa(int $item, string $status = 'Pending'): int
{
    return Database::insert('pqa_installations', [
        'item_id' => $item, 'location' => 'PT Uji', 'ppic' => 'Budi', 'install_team' => 'Tim', 'target_days' => 7, 'status' => $status,
    ]);
}

// ---------------------------------------------------------------------------
section('Skema & migrasi');

t('Migrasi: seluruh tabel terbentuk', function () {
    $want = ['users', 'categories', 'items', 'loan_batches', 'loans', 'pqa_installations', 'pqa_requests',
        'legacy_manual_loans', 'legacy_inventory_register', 'settings', 'activity_logs', 'change_events',
        'notifications', 'login_attempts', 'migration_runs', 'migration_issues', 'schema_migrations'];
    $have = array_column(Database::all('SHOW TABLES'), 'Tables_in_hme_peminjaman_test');
    foreach ($want as $tbl) {
        ok(in_array($tbl, $have, true), "tabel {$tbl} tidak ada");
    }
});

t('Migrasi: semua tabel InnoDB + utf8mb4', function () {
    $rows = Database::all("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'hme_peminjaman_test'");
    ok(count($rows) >= 17);
    foreach ($rows as $r) {
        eq('InnoDB', $r['ENGINE'], $r['TABLE_NAME']);
        contains('utf8mb4', $r['TABLE_COLLATION'], $r['TABLE_NAME']);
    }
});

t('Migrasi: dijalankan ulang → idempoten (0 diterapkan, semua dilewati)', function () {
    $m = new Migrator(Database::pdo(), HME_ROOT . '/database/migrations');
    $r = $m->migrate();
    eq(0, count($r['applied']));
    eq(count(glob(HME_ROOT . '/database/migrations/*.sql')), count($r['skipped']));
    ok(count($r['skipped']) >= 8);
    eq([], $r['modified']);
});

t('Migrasi: file yang sudah diterapkan lalu diubah dilaporkan "modified"', function () {
    $root = Database::connect(null, false);
    $root->exec('DROP DATABASE IF EXISTS `hme_peminjaman_test_mig`');
    $root->exec('CREATE DATABASE `hme_peminjaman_test_mig` CHARACTER SET utf8mb4');
    $dir = sys_get_temp_dir() . '/hme_mig_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/0001_a.sql', "-- komentar\nCREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n");
    $m = new Migrator(Database::connect('hme_peminjaman_test_mig'), $dir);
    $r = $m->migrate();
    eq(['0001_a'], $r['applied']);
    file_put_contents($dir . '/0001_a.sql', "CREATE TABLE a (id INT, extra INT);\n");
    file_put_contents($dir . '/0002_b.sql', "CREATE TABLE c (id INT);\n");
    $r = $m->migrate();
    eq(['0002_b'], $r['applied']);
    eq(['0001_a'], $r['modified']);
    $root->exec('DROP DATABASE `hme_peminjaman_test_mig`');
    array_map('unlink', glob($dir . '/*.sql'));
    rmdir($dir);
});

t('Migrator::statements: komentar dibuang, pemisah ; di akhir baris', function () {
    $s = Migrator::statements("-- c1\nCREATE TABLE x (a INT);\n\n-- c2\nINSERT INTO x VALUES (1);\n");
    eq(2, count($s));
    eq('CREATE TABLE x (a INT)', $s[0]);
});

t('Seed: data acuan ada & idempoten', function () {
    eq(6, (int) Database::value('SELECT COUNT(*) FROM categories'));
    (new Migrator(Database::pdo(), HME_ROOT . '/database/migrations'))->seed(HME_ROOT . '/database/seeds');
    eq(6, (int) Database::value('SELECT COUNT(*) FROM categories'), 'seed kedua tidak boleh menduplikasi');
    eq('wahyuardhana00@gmail.com', Database::value("SELECT value FROM settings WHERE key_name = 'admin_notification_email'"));
});

// ---------------------------------------------------------------------------
section('Koneksi & query aman');

t('Koneksi: zona waktu +07:00 selaras dengan PHP', function () {
    eq('+07:00', Database::value('SELECT @@session.time_zone'));
    $diff = abs(strtotime((string) Database::value('SELECT NOW()')) - time());
    ok($diff < 120, "NOW() selisih {$diff} detik dari PHP");
});

t('Koneksi: mode SQL ketat — data terlalu panjang & enum salah DITOLAK (tidak dipotong)', function () {
    $h = password_hash('x', PASSWORD_DEFAULT);
    $e = dbErr(fn() => Database::insert('users', ['username' => str_repeat('a', 51), 'password_hash' => $h, 'name' => 'N', 'initials' => 'N']));
    eq(1406, $e);
    $e = dbErr(fn() => Database::insert('users', ['username' => 'bad_role', 'password_hash' => $h, 'name' => 'N', 'initials' => 'N', 'role' => 'root']));
    ok(in_array($e, [1265, 1366], true), "enum salah harus ditolak, kode={$e}");
    eq(0, (int) Database::value("SELECT COUNT(*) FROM users WHERE username = 'bad_role'"));
});

t('SQL injection: payload diperlakukan sebagai data, tabel tidak terpengaruh', function () {
    dbBase();
    $before = (int) Database::value('SELECT COUNT(*) FROM users');
    eq(null, Database::one('SELECT id FROM users WHERE username = ?', ["x' OR '1'='1"]));
    eq(null, Database::one('SELECT id FROM users WHERE username = ?', ["d_user1'; DROP TABLE users; --"]));
    eq(null, Database::one('SELECT id FROM users WHERE username = ?', ['" OR ""="']));
    // Payload di kolom angka: tidak boleh memperluas hasil ke semua baris.
    $active = (int) Database::value('SELECT COUNT(*) FROM users WHERE is_active = 1');
    $sneaky = (int) Database::value('SELECT COUNT(*) FROM users WHERE is_active = ?', ['1 OR 1=1']);
    ok($sneaky <= $active, 'payload "1 OR 1=1" tidak boleh mengembalikan lebih dari baris aktif');
    ok($sneaky < $before || $active === $before, 'payload tidak boleh membuka semua baris');
    eq($before, (int) Database::value('SELECT COUNT(*) FROM users'));
});

t('Binding bertipe: int → PARAM_INT (LIMIT ? bekerja), null, bool', function () {
    dbBase();
    eq(2, count(Database::all('SELECT id FROM users ORDER BY id LIMIT ?', [2])));
    eq(1, count(Database::all('SELECT id FROM users ORDER BY id LIMIT ? OFFSET ?', [1, 1])));
    eq(1, (int) Database::value('SELECT ? IS NULL', [null]));
    eq(1, (int) Database::value('SELECT ?', [true]));
    eq(7, (int) Database::value('SELECT ? + 4', [3]));
    eq('x', Database::value('SELECT :a', ['a' => 'x']), 'parameter bernama');
});

t('SQL injection: nama tabel/kolom berbahaya di insert()/update() ditolak', function () {
    throws(fn() => Database::insert('users; DROP TABLE users', ['a' => 1]), InvalidArgumentException::class);
    throws(fn() => Database::insert('users', ['name`=1 --' => 'x']), InvalidArgumentException::class);
    throws(fn() => Database::update('users', ['name' => 'x'], []), InvalidArgumentException::class);
    ok(Database::value('SELECT COUNT(*) FROM users') !== null);
});

t('Transaksi: rollback saat exception; commit saat sukses', function () {
    $b = dbBase();
    $n0 = (int) Database::value('SELECT COUNT(*) FROM categories');
    throws(fn() => Database::transaction(function () {
        Database::insert('categories', ['name' => 'Sementara']);
        throw new RuntimeException('batal');
    }), RuntimeException::class);
    eq($n0, (int) Database::value('SELECT COUNT(*) FROM categories'), 'harus di-rollback');
    Database::transaction(fn() => Database::insert('categories', ['name' => 'Permanen']));
    eq($n0 + 1, (int) Database::value('SELECT COUNT(*) FROM categories'));
    ok(!Database::inTransaction());
});

t('Transaksi bersarang: rollback bagian dalam tidak membatalkan bagian luar', function () {
    $n0 = (int) Database::value('SELECT COUNT(*) FROM categories');
    Database::transaction(function () {
        Database::insert('categories', ['name' => 'Luar']);
        try {
            Database::transaction(function () {
                Database::insert('categories', ['name' => 'Dalam']);
                throw new RuntimeException('dalam gagal');
            });
        } catch (RuntimeException $e) {
            // sengaja ditelan
        }
    });
    eq($n0 + 1, (int) Database::value('SELECT COUNT(*) FROM categories'));
    eq(1, (int) Database::value("SELECT COUNT(*) FROM categories WHERE name = 'Luar'"));
    eq(0, (int) Database::value("SELECT COUNT(*) FROM categories WHERE name = 'Dalam'"));
});

// ---------------------------------------------------------------------------
section('Aturan bisnis di level database');

t('BR-03: satu barang TIDAK bisa punya dua loan pending (double-booking ditolak DB)', function () {
    $item = mkItem('BR3-A');
    mkLoan($item, 'pending');
    eq(E_DUP, dbErr(fn() => mkLoan($item, 'pending')));
});

t('BR-03: pending + approved pada barang sama ditolak; dua loan approved ditolak', function () {
    $item = mkItem('BR3-B');
    mkLoan($item, 'approved');
    eq(E_DUP, dbErr(fn() => mkLoan($item, 'pending')));
    eq(E_DUP, dbErr(fn() => mkLoan($item, 'approved')));
});

t('BR-03: barang berbeda boleh dipinjam bersamaan', function () {
    mkLoan(mkItem('BR3-C1'), 'pending');
    mkLoan(mkItem('BR3-C2'), 'pending');
    ok(true);
});

t('BR-03: loan berstatus rejected/returned/cancelled TIDAK memblokir peminjaman baru', function () {
    foreach (['rejected', 'returned', 'cancelled'] as $st) {
        $item = mkItem('BR3-' . $st);
        mkLoan($item, $st);
        mkLoan($item, $st);              // riwayat boleh banyak
        mkLoan($item, 'pending');        // dan barang bisa dipinjam lagi
    }
    ok(true);
});

t('BR-03: transisi pending→approved→returned membebaskan barang; approved→pending ditolak jika ada yang lain', function () {
    $item = mkItem('BR3-D');
    $l1 = mkLoan($item, 'pending');
    Database::update('loans', ['status' => 'approved'], ['id' => $l1]);
    eq(E_DUP, dbErr(fn() => mkLoan($item, 'pending')));
    Database::update('loans', ['status' => 'returned'], ['id' => $l1]);
    $l2 = mkLoan($item, 'pending');
    // mengaktifkan kembali loan lama saat loan baru aktif harus ditolak
    eq(E_DUP, dbErr(fn() => Database::update('loans', ['status' => 'approved'], ['id' => $l1])));
    ok($l2 > 0);
});

t('BR-19: satu unit PQA tidak bisa punya dua pemasangan aktif; Dicabut/Ditolak membebaskan', function () {
    $item = mkItem('PQA-X', true);
    mkPqa($item, 'Pending');
    foreach (['Pending', 'Terpasang', 'Req Cabut'] as $st) {
        eq(E_DUP, dbErr(fn() => mkPqa($item, $st)), $st);
    }
    $item2 = mkItem('PQA-Y', true);
    $a = mkPqa($item2, 'Terpasang');
    Database::update('pqa_installations', ['status' => 'Dicabut'], ['id' => $a]);
    $b = mkPqa($item2, 'Pending');
    Database::update('pqa_installations', ['status' => 'Ditolak'], ['id' => $b]);
    mkPqa($item2, 'Pending');
    mkPqa($item, 'Dicabut');
    mkPqa($item, 'Ditolak');
    ok(true);
});

t('Integritas: seq_no unik; legacy_id unik tetapi boleh banyak NULL', function () {
    $b = dbBase();
    $item = mkItem('SEQ-1');
    $seq = ++$GLOBALS['__seq'];
    $ins = fn(int $item, int $seq, ?string $legacy) => Database::insert('loans', [
        'legacy_id' => $legacy, 'seq_no' => $seq, 'batch_id' => $b['batch'], 'user_id' => $b['user'], 'item_id' => $item,
        'item_name_snapshot' => 'X', 'carrier_name_snapshot' => 'Y', 'borrowed_at' => '2026-11-04 08:00:00', 'location' => 'L', 'status' => 'returned',
    ]);
    $ins($item, $seq, 'HME-1-0');
    eq(E_DUP, dbErr(fn() => $ins($item, $seq, null)), 'seq_no ganda');
    eq(E_DUP, dbErr(fn() => $ins($item, ++$GLOBALS['__seq'], 'HME-1-0')), 'legacy_id ganda');
    $ins($item, ++$GLOBALS['__seq'], null);
    $ins($item, ++$GLOBALS['__seq'], null);
});

t('Integritas: username & kode barang unik', function () {
    dbBase();
    $h = password_hash('x', PASSWORD_DEFAULT);
    eq(E_DUP, dbErr(fn() => Database::insert('users', ['username' => 'd_user1', 'password_hash' => $h, 'name' => 'N', 'initials' => 'N'])));
    mkItem('UNIK-1');
    eq(E_DUP, dbErr(fn() => mkItem('UNIK-1')));
});

t('Foreign key: barang & user yang punya riwayat tidak bisa dihapus', function () {
    $b = dbBase();
    $item = mkItem('FK-1');
    mkLoan($item, 'returned');
    ok(in_array(dbErr(fn() => Database::run('DELETE FROM items WHERE id = ?', [$item])), [1451], true), 'hapus barang berriwayat harus ditolak');
    ok(in_array(dbErr(fn() => Database::run('DELETE FROM users WHERE id = ?', [$b['user']])), [1451], true), 'hapus user berriwayat harus ditolak');
    ok(in_array(dbErr(fn() => Database::run('DELETE FROM categories WHERE id = ?', [$b['cat']])), [1451], true));
});

t('Foreign key: loan tidak bisa menunjuk barang/user/batch yang tidak ada', function () {
    $b = dbBase();
    $bad = fn(array $over) => Database::insert('loans', $over + [
        'seq_no' => ++$GLOBALS['__seq'], 'batch_id' => $b['batch'], 'user_id' => $b['user'], 'item_id' => mkItem('FK-' . $GLOBALS['__seq']),
        'item_name_snapshot' => 'X', 'carrier_name_snapshot' => 'Y', 'borrowed_at' => '2026-11-04 08:00:00', 'location' => 'L', 'status' => 'returned',
    ]);
    eq(1452, dbErr(fn() => $bad(['item_id' => 99999999])));
    eq(1452, dbErr(fn() => $bad(['user_id' => 99999999])));
    eq(1452, dbErr(fn() => $bad(['batch_id' => 99999999])));
});

t('Status loan di luar daftar ditolak (mode ketat)', function () {
    $item = mkItem('ENUM-1');
    $e = dbErr(fn() => mkLoan($item, 'disetujui'));
    ok(in_array($e, [1265, 1366], true), "kode={$e}");
});

t('Kolom turunan active_item_key terisi otomatis sesuai status', function () {
    $item = mkItem('GEN-1');
    $id = mkLoan($item, 'pending');
    eq($item, (int) Database::value('SELECT active_item_key FROM loans WHERE id = ?', [$id]));
    Database::update('loans', ['status' => 'rejected'], ['id' => $id]);
    eq(null, Database::value('SELECT active_item_key FROM loans WHERE id = ?', [$id]));
});

t('Default: master_status = Tersedia, must_change_password = 0 (V2), is_active = 1', function () {
    $item = mkItem('DEF-1');
    eq('Tersedia', Database::value('SELECT master_status FROM items WHERE id = ?', [$item]));
    eq(0, (int) Database::value('SELECT must_change_password FROM users WHERE username = ?', ['d_user1']));
    eq(1, (int) Database::value('SELECT is_active FROM users WHERE username = ?', ['d_user1']));
});

t('Dua koneksi paralel: pemesanan barang sama serentak → tepat SATU yang berhasil', function () {
    $item = mkItem('RACE-1');
    $b = dbBase();
    $mk = function () use ($b, $item): ?int {
        $pdo = Database::connect('hme_peminjaman_test');
        try {
            $pdo->prepare("INSERT INTO loans (seq_no, batch_id, user_id, item_id, item_name_snapshot, carrier_name_snapshot, borrowed_at, location, status) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([++$GLOBALS['__seq'], $b['batch'], $b['user'], $item, 'X', 'Y', '2026-11-04 08:00:00', 'L', 'pending']);
            return 1;
        } catch (PDOException $e) {
            return null;
        }
    };
    $p1 = Database::connect('hme_peminjaman_test'); $p1->beginTransaction();
    $p2 = Database::connect('hme_peminjaman_test'); $p2->beginTransaction();
    $sql = "INSERT INTO loans (seq_no, batch_id, user_id, item_id, item_name_snapshot, carrier_name_snapshot, borrowed_at, location, status) VALUES (?,?,?,?,?,?,?,?,?)";
    $p1->prepare($sql)->execute([++$GLOBALS['__seq'], $b['batch'], $b['user'], $item, 'X', 'Y', '2026-11-04 08:00:00', 'L', 'pending']);
    $p2->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $err = null;
    try {
        $p2->prepare($sql)->execute([++$GLOBALS['__seq'], $b['batch'], $b['user2'], $item, 'X', 'Y', '2026-11-04 08:00:01', 'L', 'pending']);
    } catch (PDOException $e) {
        $err = (int) $e->errorInfo[1];   // 1205 (menunggu kunci) atau 1062 setelah p1 commit
    }
    ok($err !== null, 'koneksi kedua harus tertahan/ditolak');
    $p1->commit();
    $p2->rollBack();
    eq(1, (int) Database::value("SELECT COUNT(*) FROM loans WHERE item_id = ? AND status = 'pending'", [$item]));
});
