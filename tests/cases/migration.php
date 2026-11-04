<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Migrator;
use App\Migration\LegacyDate;
use App\Migration\LegacyImporter;
use App\Migration\LegacySource;
use App\Migration\Reconciler;
use App\Models\Setting;
use App\Services\ArchiveService;
use App\Services\ImageService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ---------------------------------------------------------------- fixture ----------------------

function mgSerial(string $dt): float
{
    return (float) XlsDate::PHPToExcel(new DateTime($dt, new DateTimeZone('Asia/Jakarta')));
}

/**
 * Ekspor sintetis yang meniru spreadsheet lama (akses kolom per posisi). $errors = true menambah baris rusak.
 * Disimpan di folder temp (di LUAR folder aplikasi, seperti seharusnya).
 */
function mgFixture(bool $errors = true, string $prefix = 'mg'): string
{
    $b = new Spreadsheet();
    $put = static function ($ws, array $rows): void {
        foreach ($rows as $r => $cols) {
            foreach ($cols as $c => $v) {
                if ($v === null) {
                    continue;
                }
                $ws->setCellValueExplicit($c . ($r + 1), $v, is_string($v) ? 's' : 'n');
            }
        }
    };
    $u = $b->getActiveSheet();
    $u->setTitle('Data_Users');
    $users = [
        ['A' => 'Username', 'B' => 'Password', 'C' => 'Role', 'D' => 'Nama Lengkap', 'E' => 'Label'],
        ['A' => "{$prefix}_admin", 'B' => 'admin-lama', 'C' => 'admin', 'D' => 'Admin HME', 'E' => 'AD'],
        ['A' => " {$prefix}_Rian", 'B' => 1234.0, 'C' => 'staff', 'D' => 'Rian  ', 'E' => 'R'],
        ['A' => "{$prefix}_rika", 'B' => 'rika123', 'C' => 'staff', 'D' => 'rika', 'E' => 'RK'],
    ];
    if ($errors) {
        $users[] = ['A' => "{$prefix}_boss", 'B' => 'x', 'C' => 'boss', 'D' => 'Bos', 'E' => 'B'];
        $users[] = ['A' => null, 'B' => 'x', 'C' => 'staff', 'D' => 'Tanpa Username', 'E' => 'T'];
    }
    $put($u, $users);

    $m = $b->createSheet();
    $m->setTitle('Master_Barang');
    $items = [
        ['A' => 'ID Barang', 'B' => 'Nama Barang', 'C' => 'URL Foto', 'D' => 'Kategori', 'E' => 'Status', 'F' => 'Kode Ref (Keterangan)'],
        ['A' => strtoupper($prefix) . '-DRN-01', 'B' => 'Drone MG', 'C' => 'https://drive.google.com/file/d/1wXa7WhGaTafJR6M48yIiUHhf3S3VjpoS/view', 'D' => 'Drone', 'E' => 'Tersedia'],
        ['A' => strtoupper($prefix) . '-HLM-03', 'B' => 'Helm MG 3', 'C' => '', 'D' => 'Lain-lain', 'E' => 'Dipinjam'],
        ['A' => strtoupper($prefix) . '-PQA-01', 'B' => 'PQA MG', 'C' => '', 'D' => 'Lain-lain', 'E' => 'Tersedia'],
    ];
    if ($errors) {
        $items[] = ['A' => strtoupper($prefix) . '-X-01', 'B' => 'Barang Kategori Aneh', 'C' => '', 'D' => 'Gaib', 'E' => 'Tersedia'];
    }
    $put($m, $items);

    $P = strtoupper($prefix);
    $l = $b->createSheet();
    $l->setTitle('Data Barang Masuk-Keluar');
    $loans = [
        ['A' => 'No', 'B' => 'Nama Alat', 'C' => 'Nama Pembawa', 'D' => 'Tanggal di bawa', 'E' => 'Tanggal kembali', 'F' => 'Tim', 'G' => 'Lokasi tujuan', 'H' => 'Keterangan', 'I' => 'Status ', 'J' => 'ID Pinjam', 'K' => 'Nama Akun', 'L' => 'ID Barang'],
        // manual: sel tanggal hasil salah-konversi Sheets ("05/04/2025" dibaca 4 Mei)
        ['A' => 1.0, 'B' => 'Kamera Termal', 'C' => 'Gabriel', 'D' => mgSerial('2025-05-04 00:00'), 'E' => '23/04/2025', 'G' => 'Hotel Azana', 'O' => 'Belum Kembali'],
        ['A' => 2.0, 'B' => 'Sketmat', 'C' => 'Victor', 'D' => '16/04/2025 11.00', 'E' => '20/04/2025 08.56', 'G' => 'MPM'],
        ['A' => 3.0, 'B' => 'Meteran Dorong', 'C' => 'Anang', 'D' => 'Tidak tahu tanggal berapa', 'E' => 'sudah kembali', 'G' => 'Survey'],
        ['A' => 4.0, 'B' => 'Tang Meter', 'C' => 'Tio', 'D' => '17/04/2025 13.00', 'E' => '17/04/2025 08.00', 'G' => 'Proyek B'],
        // kembali tanpa jam di hari yang sama → bukan kesalahan
        ['A' => 5.0, 'B' => 'Drone DJI Mini 4K', 'C' => 'Sandi', 'D' => '06/05/2025; 08:46', 'E' => mgSerial('2025-06-05 00:00'), 'G' => 'Pakuwon'],
        ['A' => 'LIST KE BAWAH BY SYSTEM INVENTORY HME'],
        // transaksi aplikasi: satu pengajuan terpecah dua milidetik (…542 / …543) dan barisnya berselang
        ['A' => 101.0, 'B' => 'Drone MG', 'C' => 'Rian ', 'D' => '24/04/2026 17.27', 'E' => '28/04/2026 15.42', 'F' => 'Rian, Vega', 'G' => 'Proyek A', 'H' => 'Ket A ', 'I' => 'returned', 'J' => 'HME-1776940058542-0', 'K' => "{$prefix}_rian", 'L' => "{$P}-DRN-01"],
        ['A' => 102.0, 'B' => 'Helm Lama', 'C' => 'Rian ', 'D' => '24/04/2026 17.27', 'E' => '28/04/2026 15.40', 'G' => 'Proyek A', 'I' => 'returned', 'J' => 'HME-1776940058543-1', 'K' => "{$prefix}_rian", 'L' => "{$P}-HLM-01"],
        ['A' => 104.0, 'B' => 'Helm MG 3', 'C' => 'rika', 'D' => '03/11/2026 09.19', 'G' => 'Project Pak Wali', 'H' => 'Tang potong', 'I' => 'approved', 'J' => 'HME-1793672399509-0', 'K' => "{$prefix}_rika", 'L' => "{$P}-HLM-03"],
        ['A' => 103.0, 'B' => 'PQA MG', 'C' => 'Rian ', 'D' => '24/04/2026 17.27', 'E' => '28/04/2026 18.00', 'G' => 'Proyek A', 'I' => 'returned', 'J' => 'HME-1776940058543-3', 'K' => "{$prefix}_rian", 'L' => "{$P}-PQA-01"],
    ];
    if ($errors) {
        $loans[] = ['A' => 105.0, 'B' => 'Hantu', 'C' => 'Ghost', 'D' => '01/11/2026 08.00', 'G' => 'X', 'I' => 'returned', 'J' => 'HME-1777000000000-0', 'K' => 'ghost', 'L' => "{$P}-DRN-01"];
    }
    $put($l, $loans);

    $q = $b->createSheet();
    $q->setTitle('Data_PQA');
    $pqa = [
        ['A' => 'ID PQA', 'B' => 'Lokasi', 'C' => 'PPIC', 'D' => 'Tim Pasang', 'E' => 'Tanggal Pasang', 'F' => 'Status', 'G' => 'Tim Cabut', 'H' => 'Tanggal Cabut', 'I' => 'Durasi Aktual', 'J' => 'Target Hari', 'K' => 'Req Tambah Hari'],
        ['A' => "{$P}-PQA-01", 'B' => 'PT Indo Green', 'C' => 'Naufal', 'D' => 'Rika, Rian', 'E' => mgSerial('2026-07-01 09:24:57'), 'F' => 'Dicabut', 'G' => 'rian', 'H' => mgSerial('2026-07-10 11:47:00'), 'I' => 9.0, 'J' => 7.0, 'K' => 0.0],
        ['A' => "{$P}-PQA-01", 'B' => 'PT Wahana', 'C' => 'Rian', 'D' => 'Naufal', 'E' => mgSerial('2026-07-20 08:00'), 'F' => 'Dicabut', 'G' => 'naufal', 'H' => mgSerial('2026-08-03 14:00'), 'I' => $errors ? 5.0 : 14.0, 'J' => 1.0, 'K' => 0.0],
    ];
    $put($q, $pqa);

    $g = $b->createSheet();
    $g->setTitle('Nama Barang Elektronik');
    $put($g, [
        ['A' => 'NO', 'B' => 'NAMA', 'C' => 'JUMLAH', 'D' => 'LOKASI PENYIMPANAN', 'E' => 'STATUS', 'F' => 'KETERANGAN'],
        ['A' => 1.0, 'B' => 'DJI MINI 2 SE', 'C' => 1.0, 'D' => 'MEJA LT 01', 'E' => 'BAIK', 'F' => 'LEMARI ALAT LT 01 '],
        ['A' => 2.0, 'B' => 'Drone MG', 'C' => 2.0, 'D' => 'LEMARI', 'E' => 'BAIK', 'F' => 'DI PROYEK', 'G' => 'catatan'],
        [],
        ['A' => 'LIST BARANG KEMBALI KE KANTOR DARI PROYEK TUBAN'],
        ['A' => 'NO', 'B' => 'NAMA', 'C' => 'JUMLAH', 'D' => 'SATUAN', 'E' => 'LOKASI PENYIMPANAN', 'F' => 'STATUS', 'G' => 'KETERANGAN'],
        ['A' => 1.0, 'B' => 'GERINDA MAKITA', 'C' => 1.0, 'D' => 'PCS', 'E' => 'LEMARI ALAT LT 1', 'F' => 'BAIK ', 'G' => 'KEMBALI DARI TUBAN'],
    ]);

    $path = sys_get_temp_dir() . '/hme_mig_' . $prefix . ($errors ? '_err' : '_ok') . '.xlsx';
    (new Xlsx($b))->save($path);
    $b->disconnectWorksheets();
    return $path;
}

/** Jalankan $fn pada database terpisah yang baru dibuat (untuk rekonsiliasi pada DB bersih). */
function mgWithDatabase(string $name, callable $fn): void
{
    $root = Database::connect(null, false);
    $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    $root->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mig = new Migrator(Database::connect($name), HME_ROOT . '/database/migrations');
    $mig->migrate();
    $mig->seed(HME_ROOT . '/database/seeds');
    $prev = (string) getenv('DB_NAME');
    putenv("DB_NAME={$name}");
    Config::load();
    Database::disconnect();
    try {
        $fn();
    } finally {
        putenv("DB_NAME={$prev}");
        Config::load();
        Database::disconnect();
        $root->exec("DROP DATABASE IF EXISTS `{$name}`");
    }
}

function mgCount(string $table, string $where = '1', array $p = []): int
{
    return (int) Database::value("SELECT COUNT(*) FROM {$table} WHERE {$where}", $p);
}

// ---------------------------------------------------------------------------
section('Migrasi — parser tanggal (§8)');

t('Format aplikasi, teks bebas (: . ; tanpa jam), sel Sheets tertukar, tanggal mustahil → null', function () {
    eq('2026-04-24 17:27:00', LegacyDate::appText('24/04/2026 17.27'));
    eq(null, LegacyDate::appText('24/04/2026 17:27'), 'aplikasi selalu memakai titik');
    eq('2025-05-06 08:46:00', LegacyDate::freeText('06/05/2025; 08:46'));
    eq('2025-04-23 00:00:00', LegacyDate::freeText('23/04/2025'));
    eq('2025-09-11 13:00:00', LegacyDate::freeText(' 11/9/2025 13.00 '));
    eq('2026-08-22 08:03:00', LegacyDate::freeText('22/08/2026 08:03'));
    foreach (['31/02/2026', '19/01/1016', '12/13/2025', 'sudah kembali', '', '1/1/2025 25.00'] as $bad) {
        eq(null, LegacyDate::freeText($bad), $bad);
    }
    eq('2025-04-05 00:00:00', LegacyDate::serialSwapped(mgSerial('2025-05-04 00:00')), 'Sheets 4 Mei → sebenarnya 5 April');
    eq('2025-04-05 10:00:00', LegacyDate::serialSwapped(mgSerial('2025-05-04 10:00')));
    eq(null, LegacyDate::serialSwapped(mgSerial('2025-05-20 00:00')), 'hari > 12 tidak mungkin hasil penukaran');
    eq('2026-07-01 09:24:57', LegacyDate::serial(mgSerial('2026-07-01 09:24:57')), 'tanggal PQA asli tidak ditukar');
});

// ---------------------------------------------------------------------------
section('Migrasi — impor (dry-run, nyata, idempoten)');

t('Dry-run: semua dijalankan lalu dibatalkan — tidak ada data tersimpan, laporan per baris tetap tersimpan', function () {
    $src = new LegacySource(mgFixture(true, 'mga'));
    $before = [mgCount('users'), mgCount('items'), mgCount('loans'), mgCount('legacy_manual_loans'), mgCount('pqa_installations'), Setting::get('loan_seq')];
    $s = LegacyImporter::run($src, true);
    eq(['dry', 'success'], [$s['mode'], $s['status']]);
    eq($before, [mgCount('users'), mgCount('items'), mgCount('loans'), mgCount('legacy_manual_loans'), mgCount('pqa_installations'), Setting::get('loan_seq')]);
    eq(3, $s['stats']['users_created']);
    eq(4, $s['stats']['loans_created']);
    ok(mgCount('migration_issues', 'run_id = ?', [$s['run_id']]) > 20, 'laporan per baris tersimpan');
    eq('dry', Database::value('SELECT mode FROM migration_runs WHERE id = ?', [$s['run_id']]));
});

t('Nyata: akun (hash, wajib ganti, nama dirapikan), barang (+ yatim nonaktif, kategori tak dikenal), transaksi & pengajuan, arsip, PQA, register', function () {
    $src = new LegacySource(mgFixture(true, 'mgb'));
    $seqBefore = Setting::int('loan_seq', 0);
    Database::run("UPDATE settings SET value = '100' WHERE key_name = 'loan_seq' AND CAST(value AS UNSIGNED) < 100");
    $s = LegacyImporter::run($src, false);
    eq('success', $s['status'], (string) $s['error']);

    // Akun
    $rian = Database::one("SELECT * FROM users WHERE username = 'mgb_rian'");
    ok($rian !== null && password_verify('1234', (string) $rian['password_hash']), 'password angka di-hash dari "1234"');
    eq(['Rian', 'R', 'staff', 1, 1], [$rian['name'], $rian['initials'], $rian['role'], (int) $rian['must_change_password'], (int) $rian['is_active']]);
    eq(0, mgCount('users', "username IN ('mgb_boss', '')"));
    eq(2, mgCount('migration_issues', "run_id = ? AND sheet = 'Data_Users' AND category = 'failed'", [$s['run_id']]));
    eq(0, mgCount('activity_logs', "after_json LIKE '%admin-lama%' OR after_json LIKE '%rika123%'"), 'password tidak tercatat');
    eq(0, mgCount('migration_issues', "raw_json LIKE '%admin-lama%' OR raw_json LIKE '%rika123%'"), 'password tidak ada di laporan');

    // Barang
    $drn = Database::one("SELECT * FROM items WHERE code = 'MGB-DRN-01'");
    eq(['https://lh3.googleusercontent.com/d/1wXa7WhGaTafJR6M48yIiUHhf3S3VjpoS', 1, 0], [$drn['photo_legacy_url'], (int) $drn['is_active'], (int) $drn['is_pqa']]);
    eq(1, (int) Database::value("SELECT is_pqa FROM items WHERE code = 'MGB-PQA-01'"));
    $orphan = Database::one("SELECT * FROM items WHERE code = 'MGB-HLM-01'");
    eq([0, 'Helm Lama'], [(int) $orphan['is_active'], $orphan['name']], 'barang terhapus dibuat ulang nonaktif');
    eq('Lain-lain', Database::value("SELECT c.name FROM items i JOIN categories c ON c.id = i.category_id WHERE i.code = 'MGB-X-01'"));
    ok(mgCount('migration_issues', "run_id = ? AND message LIKE '%Kategori \"Gaib\"%'", [$s['run_id']]) === 1);

    // Transaksi & pengajuan
    $l = Database::all("SELECT l.*, i.code FROM loans l JOIN items i ON i.id = l.item_id WHERE l.legacy_id LIKE 'HME-17769400585%' OR l.legacy_id = 'HME-1793672399509-0' ORDER BY l.legacy_row");
    eq(4, count($l));
    eq(1, count(array_unique(array_map(fn($x) => $x['batch_id'], array_filter($l, fn($x) => strpos($x['legacy_id'], 'HME-17769400585') === 0)))),
        'satu pengajuan walau milidetik berganti & baris berselang');
    $byId = array_column($l, null, 'legacy_id');
    eq(['2026-04-24 17:27:00', '2026-04-28 15:42:00', 'returned', 'Rian ', 'Ket A ', 101], [
        $byId['HME-1776940058542-0']['borrowed_at'], $byId['HME-1776940058542-0']['returned_at'], $byId['HME-1776940058542-0']['status'],
        $byId['HME-1776940058542-0']['carrier_name_snapshot'], $byId['HME-1776940058542-0']['notes'], (int) $byId['HME-1776940058542-0']['seq_no']]);
    eq([null, 'approved'], [$byId['HME-1793672399509-0']['returned_at'], $byId['HME-1793672399509-0']['status']]);
    eq('HME-1776940058542', Database::value('SELECT legacy_prefix FROM loan_batches WHERE id = ?', [$byId['HME-1776940058542-0']['batch_id']]));
    eq(0, mgCount('loans', "legacy_id = 'HME-1777000000000-0'"), 'akun tak dikenal → gagal');
    ok(mgCount('migration_issues', "run_id = ? AND category = 'failed' AND message LIKE '%akun \"ghost\" tidak ada%'", [$s['run_id']]) === 1);
    ok(Setting::int('loan_seq', 0) >= max(104, $seqBefore), 'nomor urut melanjutkan data lama');
    eq('Dipinjam', Database::value("SELECT master_status FROM items WHERE code = 'MGB-HLM-03'"), 'status diturunkan dari loan approved');

    // Arsip manual
    $man = array_column(Database::all('SELECT * FROM legacy_manual_loans WHERE source_row BETWEEN 2 AND 6 ORDER BY source_row'), null, 'source_row');
    if (count($man) === 5) {   // tes lain mungkin sudah mengisi baris yang sama; periksa hanya bila run ini yang mengisinya
        eq(['swapped_dm', '2025-04-05 00:00:00', '2025-04-23 00:00:00', 'Belum Kembali'], [$man[2]['parse_flag'], $man[2]['borrowed_at'], $man[2]['returned_at'], $man[2]['extra_note']]);
        eq('ok', $man[3]['parse_flag']);
        eq(['unparseable', null, 'sudah kembali'], [$man[4]['parse_flag'], $man[4]['borrowed_at'], $man[4]['return_note']]);
        eq('needs_review', $man[5]['parse_flag'], 'kembali lebih awal dari bawa');
        eq(['swapped_dm', '2025-05-06 00:00:00'], [$man[6]['parse_flag'], $man[6]['returned_at']], 'tanggal tanpa jam di hari yang sama bukan kesalahan');
    }
    eq(1, $s['stats']['manual_separators']);
    eq(5, $s['stats']['manual_created']);

    // PQA
    $p = Database::all("SELECT * FROM pqa_installations WHERE legacy_row IN (2,3) AND item_id = ? ORDER BY legacy_row", [(int) Database::value("SELECT id FROM items WHERE code = 'MGB-PQA-01'")]);
    eq([['2026-07-01 09:24:57', '2026-07-10 11:47:00', 9, 7, 'Dicabut', null, null]],
        [[$p[0]['installed_at'], $p[0]['removed_at'], (int) $p[0]['total_days'], (int) $p[0]['target_days'], $p[0]['status'], $p[0]['submitted_at'], $p[0]['requested_by']]]);
    ok(mgCount('migration_issues', "run_id = ? AND message LIKE '%Durasi di sheet 5 hari, dihitung ulang 14 hari%'", [$s['run_id']]) === 1);

    // Register
    eq(3, $s['stats']['register_created']);
    $g = Database::one("SELECT * FROM legacy_inventory_register WHERE name = 'GERINDA MAKITA' ORDER BY id DESC LIMIT 1");
    eq(['List Barang Kembali Ke Kantor Dari Proyek Tuban', 'PCS', 'LEMARI ALAT LT 1', 'BAIK', 'KEMBALI DARI TUBAN'], [$g['section'], $g['unit'], $g['storage_location'], $g['condition_text'], $g['current_position']]);
});

t('Menjalankan ulang tidak menggandakan apa pun (semua "duplikat"); akun yang sudah ada tidak ditimpa', function () {
    aUser('mgc_admin', 'Password-Lama-Ku-1', 'admin');
    $src = new LegacySource(mgFixture(true, 'mgc'));
    LegacyImporter::run($src, false);
    $counts = [mgCount('users'), mgCount('items'), mgCount('loans'), mgCount('loan_batches'), mgCount('pqa_installations'), mgCount('legacy_inventory_register')];
    $s = LegacyImporter::run($src, false);
    eq($counts, [mgCount('users'), mgCount('items'), mgCount('loans'), mgCount('loan_batches'), mgCount('pqa_installations'), mgCount('legacy_inventory_register')]);
    eq(0, $s['stats']['loans_created'] ?? 0);
    ok($s['counts']['duplicate'] >= 15);
    ok(password_verify('Password-Lama-Ku-1', (string) Database::value("SELECT password_hash FROM users WHERE username = 'mgc_admin'")), 'akun yang ada tidak diubah');
});

t('CLI menolak berkas sumber di dalam folder aplikasi (berisi password lama); tanpa --source → keterangan pemakaian', function () {
    $inside = HME_ROOT . '/storage/cache/legacy_test.xlsx';
    copy(mgFixture(true, 'mgd'), $inside);
    try {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/migrate-legacy.php') . ' --source=' . escapeshellarg($inside) . ' --dry-run 2>&1', $out, $code);
        eq(2, $code);
        contains('tidak boleh disimpan di dalam folder aplikasi', implode("\n", $out));
    } finally {
        @unlink($inside);
    }
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/migrate-legacy.php') . ' 2>&1', $out2, $code2);
    eq(2, $code2);
    contains('--source', implode("\n", $out2));
});

// ---------------------------------------------------------------------------
section('Migrasi — rekonsiliasi & paritas (database bersih)');

t('Rekonsiliasi pada DB bersih: semua cocok (termasuk paritas getMonthlyStats & Laporan Stok); perubahan data terdeteksi; CLI nyata exit 0', function () {
    $file = mgFixture(false, 'rc');
    mgWithDatabase('hme_peminjaman_test_mig', function () use ($file) {
        $src = new LegacySource($file);
        $s = LegacyImporter::run($src, false);
        eq('success', $s['status']);
        $r = Reconciler::run($src);
        $bad = array_values(array_filter($r['checks'], fn($c) => !$c['ok']));
        eq([], array_map(fn($c) => $c['label'] . ': ' . json_encode([$c['expected'], $c['actual'], $c['details']]), $bad));
        ok(count($r['checks']) >= 15);
        $par = array_values(array_filter($r['checks'], fn($c) => $c['group'] === 'Paritas'));
        eq(2, count($par));

        Database::run("UPDATE loans SET status = 'rejected' WHERE legacy_id = 'HME-1776940058542-0'");
        $r2 = Reconciler::run($src);
        eq(false, $r2['ok']);
        $labels = array_column(array_filter($r2['checks'], fn($c) => !$c['ok']), 'label');
        ok(in_array('Per status', $labels, true) && in_array('Rekap getMonthlyStats (total, status, ranking, daftar aktif)', $labels, true), json_encode($labels));
        Database::run("UPDATE loans SET status = 'returned' WHERE legacy_id = 'HME-1776940058542-0'");

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HME_ROOT . '/tools/migrate-legacy.php') . ' --source=' . escapeshellarg($file) . ' 2>&1', $out, $code);
        eq(0, $code, implode("\n", array_slice($out, -15)));
        contains('SEMUA COCOK', implode("\n", $out));
        $sum = json_decode((string) Database::value("SELECT summary_json FROM migration_runs WHERE mode = 'real' ORDER BY id DESC LIMIT 1"), true);
        eq(true, $sum['reconciliation']['ok'], 'hasil rekonsiliasi tersimpan untuk halaman admin');
    });
});

// ---------------------------------------------------------------------------
section('Migrasi — arsip & laporan (admin)');

t('Arsip manual: cari & filter tanda; register: bagian & penanda "sudah di master"; laporan run/rincian/XLSX; filter salah → 422', function () {
    $m = ArchiveService::manual([], 100, 0);
    ok($m['total'] >= 5 && array_sum($m['flags']) === $m['total']);
    foreach (ArchiveService::manual(['filter' => 'needs_review'], 100, 0)['items'] as $r) { eq('needs_review', $r['parse_flag']); }
    ok(ArchiveService::manual(['q' => 'Kamera Termal'], 10, 0)['total'] >= 1);
    eq(0, ArchiveService::manual(['q' => '%_%'], 10, 0)['total'], 'wildcard literal');
    $g = ArchiveService::register(['q' => 'Drone MG'], 10, 0);
    ok($g['total'] >= 1 && $g['items'][0]['in_master'] === true, 'nama sudah ada di master barang');
    eq(false, ArchiveService::register(['q' => 'GERINDA MAKITA'], 10, 0)['items'][0]['in_master']);
    ok(isset(ArchiveService::register([], 1, 0)['sections']['Inventaris Kantor']));
    foreach ([['filter' => 'nope'], ['q' => str_repeat('x', 101)], ['q' => ['a']]] as $bad) {
        eq(422, throws(fn() => ArchiveService::manual($bad, 10, 0), HttpException::class)->status(), json_encode($bad));
    }

    $runs = ArchiveService::runs();
    ok(count($runs) >= 3 && isset($runs[0]['counts']['ok']));
    $run = (int) $runs[0]['id'];
    $iss = ArchiveService::issues($run, ['filter' => 'duplicate'], 100, 0);
    foreach ($iss['items'] as $i) { eq('duplicate', $i['category']); }
    eq(404, throws(fn() => ArchiveService::issues(99999999, [], 10, 0), HttpException::class)->status());

    Database::run("INSERT INTO migration_issues (run_id, sheet, source_row, category, message) VALUES (?, 'X', 1, 'failed', '=HYPERLINK(\"http://evil\")')", [$run]);
    $x = ArchiveService::issuesXlsx($run);
    eq('Laporan_Migrasi_HME_run' . $run . '.xlsx', $x['filename']);
    $tmp = tempnam(sys_get_temp_dir(), 'mgx') . '.xlsx';
    file_put_contents($tmp, $x['bytes']);
    try {
        $ws = IOFactory::load($tmp)->getSheet(0);
        eq(['Kategori', 'Sheet', 'Baris', 'Keterangan', 'Data'], $ws->rangeToArray('A1:E1')[0]);
        eq('Gagal', $ws->getCell('A2')->getValue(), 'baris gagal diurutkan paling atas');
        $found = false;
        for ($i = 2; $i <= $ws->getHighestDataRow(); $i++) {
            if ($ws->getCell("D{$i}")->getValue() === '=HYPERLINK("http://evil")') {
                eq('s', $ws->getCell("D{$i}")->getDataType(), 'tetap teks, bukan rumus');
                $found = true;
            }
        }
        ok($found);
    } finally {
        @unlink($tmp);
    }
});

t('ImageService::storeLocal (CLI): foto lokal diproses ulang seperti unggahan; bukan gambar → 422', function () {
    $f = tempnam(sys_get_temp_dir(), 'mgimg');
    file_put_contents($f, imgBytes('jpeg', 800, 600));
    $name = ImageService::storeLocal($f);
    ok(ImageService::isValidName($name) && is_file(ImageService::path($name)) && is_file(ImageService::path(ImageService::thumbName($name))));
    file_put_contents($f, '<?php echo 1;');
    eq(422, throws(fn() => ImageService::storeLocal($f), HttpException::class)->status());
    eq(422, throws(fn() => ImageService::storeLocal($f . '-tidak-ada'), HttpException::class)->status());
    @unlink($f);
    ImageService::delete($name);
});
