<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Services\AvailabilityService;
use App\Services\ImageService;
use App\Services\ItemService;
use App\Services\ReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ---------------------------------------------------------------- pembantu --------------------

/** Sisipkan loan langsung (tanggal bebas, status apa pun) — data uji rekap. */
function rLoan(int $userId, int $itemId, string $name, string $status, string $borrowedAt, array $o = []): int
{
    $batch = Database::insert('loan_batches', ['user_id' => $userId]);
    $seq = AvailabilityService::nextLoanSeq();
    return Database::insert('loans', $o + [
        'legacy_id' => 'HME-R' . $seq . '-0', 'seq_no' => $seq, 'batch_id' => $batch, 'user_id' => $userId, 'item_id' => $itemId,
        'item_name_snapshot' => $name, 'carrier_name_snapshot' => 'Peminjam ' . $userId, 'borrowed_at' => $borrowedAt,
        'returned_at' => $status === 'returned' ? $borrowedAt : null, 'location' => 'Lokasi Uji', 'status' => $status,
    ]);
}

function rErr(callable $fn): HttpException
{
    /** @var HttpException $e */
    $e = throws($fn, HttpException::class);
    return $e;
}

/**
 * Port BARIS-PER-BARIS dari getMonthlyStats() (Code.gs lama) + pengurutan di klien lama (index.html: sort b[1]-a[1]).
 * $sheet: baris [tanggal "dd/mm/yyyy HH.MM", nama alat, nama pembawa, status, ID pinjam] dalam urutan sheet.
 *
 * @param array<int,array{0:string,1:string,2:string,3:string,4:string}> $sheet
 * @return array<string,mixed>
 */
function legacyMonthlyStats(array $sheet, string $m, string $y): array
{
    $s = ['total' => 0, 'approved' => 0, 'returned' => 0, 'pending' => 0, 'rejected' => 0, 'topItems' => [], 'activeList' => []];
    $yStr = strtolower(trim($y));
    $isAllYears = $yStr === 'semua' || $yStr === 'all' || $yStr === '';
    $yearStart = 0; $yearEnd = 9999;
    if (!$isAllYears) {
        if (strpos($yStr, '-') !== false) {
            $pts = explode('-', $yStr);
            $yearStart = (int) trim($pts[0]); $yearEnd = (int) trim($pts[1]);
        } else {
            $yearStart = $yearEnd = (int) $yStr;
        }
    }
    $isAllMonths = strtolower($m) === 'all';
    foreach ($sheet as [$tgl, $nama, $pembawa, $st, $id]) {
        if ($tgl === '' || $id === '') {
            continue;                                   // catatan manual (tanpa ID) tidak dihitung
        }
        $mm = -1; $yy = -1;
        $p = explode('/', explode(' ', $tgl)[0]);
        if (count($p) >= 3) { $mm = (int) $p[1]; $yy = (int) $p[2]; }
        $matchMonth = $isAllMonths || $mm === (int) $m;
        $matchYear = $isAllYears || ($yy >= $yearStart && $yy <= $yearEnd);
        if ($matchMonth && $matchYear) {
            $s['total']++;
            if ($st === 'approved') { $s['approved']++; $s['activeList'][] = ['namaAlat' => $nama, 'peminjam' => $pembawa]; }
            if ($st === 'returned') { $s['returned']++; }
            if ($st === 'pending') { $s['pending']++; }
            if ($st === 'rejected') { $s['rejected']++; }
            $k = 'k:' . $nama;
            $s['topItems'][$k] = [$nama, ($s['topItems'][$k][1] ?? 0) + 1];
        }
    }
    $sorted = array_values($s['topItems']);
    usort($sorted, static fn($a, $b) => $b[1] <=> $a[1]);   // Array.prototype.sort stabil (V8)
    $s['topItems'] = $sorted;
    return $s;
}

/** "Sheet" lama dibangun dari database (urutan baris = seq_no), plus 2 catatan manual tanpa ID. */
function legacySheetFromDb(): array
{
    $rows = [['03/04/2031 08.00', 'Catatan Manual', 'Orang Luar', '', ''], ['15/04/2031 09.00', 'Drone DJI', 'Orang Luar', '', '']];
    // Setiap baris tabel loans adalah transaksi aplikasi (berID). Sebagian loan buatan tes lain tidak mengisi legacy_id → beri ID pengganti.
    foreach (Database::all("SELECT seq_no, borrowed_at, item_name_snapshot, carrier_name_snapshot, status, legacy_id FROM loans WHERE status <> 'cancelled' ORDER BY seq_no, id") as $r) {
        $rows[] = [date('d/m/Y H.i', strtotime((string) $r['borrowed_at'])), (string) $r['item_name_snapshot'], (string) $r['carrier_name_snapshot'], (string) $r['status'], (string) ($r['legacy_id'] ?? ('HME-SEQ-' . $r['seq_no']))];
    }
    return $rows;
}

/** @var array<string,int>|null */
$GLOBALS['__rData'] = null;

/** Dataset rekap terkontrol di tahun 2031–2032 (dibuat sekali). @return array<string,int> */
function rData(): array
{
    if ($GLOBALS['__rData'] !== null) {
        return $GLOBALS['__rData'];
    }
    $u = lStaff('r_staff1')['id'];
    $v = lStaff('r_staff2')['id'];
    $it = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $k) {
        $it[$k] = lItem('Rekap ' . $k);
    }
    rLoan($u, $it['A'], 'Drone DJI', 'returned', '2031-04-03 08:00:00');
    rLoan($v, $it['A'], 'Drone DJI', 'returned', '2031-04-10 09:00:00');
    rLoan($u, $it['B'], 'Meteran Laser 70M', 'returned', '2031-04-11 10:00:00');
    rLoan($v, $it['B'], 'Meteran Laser 70M ', 'rejected', '2031-04-12 10:00:00');    // spasi di belakang = nama lain (sama seperti lama)
    rLoan($u, $it['C'], 'meteran laser 70M', 'pending', '2031-04-15 11:00:00');      // huruf kecil = nama lain
    rLoan($v, $it['D'], 'Helm', 'cancelled', '2031-04-20 12:00:00', ['cancelled_at' => '2031-04-20 13:00:00']);
    rLoan($v, $it['E'], 'Kunci', 'approved', '2031-04-30 23:59:59', ['carrier_name_snapshot' => 'Vega Uji']);
    rLoan($u, $it['F'], 'Kunci', 'returned', '2031-05-01 00:00:00');
    rLoan($u, $it['F'], 'Tang', 'approved', '2031-12-31 18:00:00', ['carrier_name_snapshot' => 'Rian Uji']);
    rLoan($v, $it['A'], 'Drone DJI', 'returned', '2032-01-01 07:00:00');
    rLoan($v, $it['G'], 'Tang', 'returned', '2032-04-05 07:00:00');
    return $GLOBALS['__rData'] = $it;
}

// ---------------------------------------------------------------------------
section('Rekap — filter periode (BR-26)');

t('Bulan & tahun: tunggal, rentang (spasi bebas), semua — label & parameter nama berkas', function () {
    $p = ReportService::period('4', '2026');
    eq([4, 2026, 2026, 'April 2026', '4', '2026'], [$p['month'], $p['year_from'], $p['year_to'], $p['label'], $p['month_param'], $p['year_param']]);
    $p = ReportService::period('all', ' 2025 - 2026 ');
    eq([null, 2025, 2026, 'Semua bulan 2025–2026', 'all', '2025-2026'], [$p['month'], $p['year_from'], $p['year_to'], $p['label'], $p['month_param'], $p['year_param']]);
    $p = ReportService::period('all', 'semua');
    eq([null, null, null, 'Semua data', 'all', 'semua'], [$p['month'], $p['year_from'], $p['year_to'], $p['label'], $p['month_param'], $p['year_param']]);
    foreach ([[null, null], ['', ''], ['ALL', 'All'], ['semua', 'SEMUA']] as [$m, $y]) {
        eq('Semua data', ReportService::period($m, $y)['label'], json_encode([$m, $y]));
    }
    eq('Desember (semua tahun)', ReportService::period('12', 'semua')['label']);
    eq([2026, 2026], [ReportService::period(7, 2026)['year_from'], ReportService::period(7, 2026)['year_to']], 'integer diterima');
});

t('Filter tidak valid → 422 per field (bulan 0/13/teks/array, tahun 2 digit/terbalik/di luar batas/teks/array)', function () {
    foreach (['0', '13', '-1', 'april', '4.5', ' 4x'] as $m) {
        $e = rErr(fn() => ReportService::period($m, '2026'));
        eq(422, $e->status(), "bulan {$m}"); ok(isset($e->fields()['month']), "bulan {$m}");
    }
    ok(isset(rErr(fn() => ReportService::period(['4'], '2026'))->fields()['month']));
    foreach (['26', '2026-2025', '1999', '2101', '2026-', '-2026', 'abc', '2026; DROP TABLE loans', '2025-2026-2027', '20262'] as $y) {
        $e = rErr(fn() => ReportService::period('4', $y));
        eq(422, $e->status(), "tahun {$y}"); ok(isset($e->fields()['year']), "tahun {$y}");
    }
    ok(isset(rErr(fn() => ReportService::period('4', ['2026']))->fields()['year']));
});

// ---------------------------------------------------------------------------
section('Rekap — hitungan & paritas dengan getMonthlyStats lama');

t('April 2031: total/selesai/aktif/menunggu/ditolak, cancelled tidak dihitung, ranking nama PERSIS + urutan seri, daftar aktif', function () {
    rData();
    $s = ReportService::summary(ReportService::period('4', '2031'));
    eq([6, 1, 3, 1, 1], [$s['total'], $s['approved'], $s['returned'], $s['pending'], $s['rejected']]);
    eq([
        ['name' => 'Drone DJI', 'count' => 2],
        ['name' => 'Meteran Laser 70M', 'count' => 1],
        ['name' => 'Meteran Laser 70M ', 'count' => 1],
        ['name' => 'meteran laser 70M', 'count' => 1],
        ['name' => 'Kunci', 'count' => 1],
    ], $s['top_items']);
    eq(1, count($s['active']));
    eq(['Kunci', 'Vega Uji', '2031-04-30 23:59:59'], [$s['active'][0]['item_name'], $s['active'][0]['borrower'], $s['active'][0]['borrowed_at']]);
    ok(!in_array('Helm', array_column($s['top_items'], 'name'), true), 'loan dibatalkan tidak masuk ranking');
    eq('April 2031', $s['period']['label']);
});

t('Batas bulan/tahun memakai TANGGAL PINJAM: 30 Apr 23:59:59 ≠ Mei; rentang 2031-2032; semua bulan 2031', function () {
    rData();
    $mei = ReportService::summary(ReportService::period('5', '2031'));
    eq([1, 1, ['Kunci']], [$mei['total'], $mei['returned'], array_column($mei['top_items'], 'name')]);
    $y = ReportService::summary(ReportService::period('all', '2031'));
    eq([8, 2], [$y['total'], $y['approved']]);
    eq(['Kunci', 'Tang'], array_column($y['active'], 'item_name'));
    $r = ReportService::summary(ReportService::period('all', '2031-2032'));
    eq(10, $r['total']);
    eq(['name' => 'Drone DJI', 'count' => 3], $r['top_items'][0]);
    eq(0, ReportService::summary(ReportService::period('6', '2031'))['total'], 'bulan kosong');
    $jan = ReportService::summary(ReportService::period('1', '2031-2032'));
    eq([1, 'Drone DJI'], [$jan['total'], $jan['top_items'][0]['name']]);
});

t('Paritas: 12 bulan × (2031, 2031-2032, semua) + semua bulan per tahun + semua data = hasil port getMonthlyStats lama', function () {
    rData();
    $sheet = legacySheetFromDb();
    $cases = [];
    foreach (['2031', '2031-2032', 'semua', '2032'] as $y) {
        for ($m = 1; $m <= 12; $m++) {
            $cases[] = [(string) $m, $y];
        }
        $cases[] = ['all', $y];
    }
    $now = (int) date('Y');
    $cases[] = ['all', ($now - 1) . '-' . $now];
    foreach ($cases as [$m, $y]) {
        $old = legacyMonthlyStats($sheet, $m, $y);
        $new = ReportService::summary(ReportService::period($m, $y));
        $tag = "{$m}/{$y}";
        eq([$old['total'], $old['approved'], $old['returned'], $old['pending'], $old['rejected']],
           [$new['total'], $new['approved'], $new['returned'], $new['pending'], $new['rejected']], "hitungan {$tag}");
        eq($old['topItems'], array_map(fn($t) => [$t['name'], $t['count']], $new['top_items']), "ranking {$tag}");
        eq($old['activeList'], array_map(fn($a) => ['namaAlat' => $a['item_name'], 'peminjam' => $a['borrower']], $new['active']), "aktif {$tag}");
    }
    ok(count($cases) >= 50);
});

// ---------------------------------------------------------------------------
section('Laporan Excel — sheet "Laporan Stok" (BR-28, F2)');

t('Kolom Jumlah/Status/Peminjam Terakhir/Keterangan sesuai aturan lama; Peminjam Terakhir hanya dari approved/returned; barang nonaktif tidak tampil', function () {
    $u = lStaff('r_staff1')['id']; $v = lStaff('r_staff2')['id'];
    $s1 = iMake(['name' => 'Stok Tanpa Riwayat'])['code'];
    $i2 = iMake(['name' => 'Stok Pernah Dipinjam']);
    rLoan($u, $i2['id'], 'Stok Pernah Dipinjam', 'returned', '2031-02-01 08:00:00', ['location' => 'Proyek Lama', 'carrier_name_snapshot' => 'Naufal Uji']);
    rLoan($v, $i2['id'], 'Stok Pernah Dipinjam', 'rejected', '2031-03-01 08:00:00', ['location' => 'Proyek Ditolak', 'carrier_name_snapshot' => 'Ditolak Uji']);
    rLoan($v, $i2['id'], 'Stok Pernah Dipinjam', 'pending', '2031-03-02 08:00:00', ['location' => 'Proyek Menunggu', 'carrier_name_snapshot' => 'Menunggu Uji']);
    $i3 = iMake(['name' => 'Stok Dipinjam Ket']);
    rLoan($u, $i3['id'], 'Stok Dipinjam Ket', 'returned', '2031-01-01 08:00:00', ['location' => 'Awal']);
    rLoan($u, $i3['id'], 'Stok Dipinjam Ket', 'approved', '2031-02-01 08:00:00', ['location' => 'Site Y', 'notes' => 'Survey atap', 'carrier_name_snapshot' => 'Rian Uji']);
    AvailabilityService::sync($i3['id']);
    $i4 = iMake(['name' => 'Stok Dipinjam Tanpa Ket']);
    rLoan($v, $i4['id'], 'Stok Dipinjam Tanpa Ket', 'approved', '2031-02-01 08:00:00', ['location' => 'Site Z', 'notes' => '', 'carrier_name_snapshot' => 'Vega Uji']);
    AvailabilityService::sync($i4['id']);
    $i5 = iMake(['name' => 'Stok Nonaktif']);
    ItemService::setActive($i5['id'], false, iActor(), ureq());

    $rows = [];
    foreach (ReportService::stockRows() as $r) {
        $rows[$r['code']] = $r;
    }
    eq([1, 'Tersedia', '-', 'Di Gudang'], [$rows[$s1]['qty'], $rows[$s1]['status'], $rows[$s1]['borrower'], $rows[$s1]['note']]);
    eq([1, 'Tersedia', 'Naufal Uji', 'Di Gudang (Terakhir kembali dari Proyek Lama)'],
       [$rows[$i2['code']]['qty'], $rows[$i2['code']]['status'], $rows[$i2['code']]['borrower'], $rows[$i2['code']]['note']], 'F2: pending/ditolak diabaikan');
    eq([0, 'Dipinjam', 'Rian Uji', 'Dibawa ke Site Y (Ket: Survey atap)'],
       [$rows[$i3['code']]['qty'], $rows[$i3['code']]['status'], $rows[$i3['code']]['borrower'], $rows[$i3['code']]['note']]);
    eq([0, 'Dipinjam', 'Vega Uji', 'Dibawa ke Site Z'],
       [$rows[$i4['code']]['qty'], $rows[$i4['code']]['status'], $rows[$i4['code']]['borrower'], $rows[$i4['code']]['note']], 'tanpa keterangan → tanpa "(Ket:"');
    ok(!isset($rows[$i5['code']]), 'barang nonaktif tidak masuk laporan stok');
    $codes = array_keys($rows);
    ok(array_search($s1, $codes, true) < array_search($i4['code'], $codes, true), 'urut sesuai urutan master (id)');
    eq(range(1, count($rows)), array_values(array_column($rows, 'no')), 'nomor urut 1..n tanpa celah');
});

// ---------------------------------------------------------------------------
section('Laporan Excel — berkas XLSX (Q9)');

t('Dua sheet + header persis; Transaksi Periode mengikuti filter; tanggal bertipe tanggal; foto tersemat; teks "=..." TIDAK menjadi rumus; audit', function () {
    rData();
    ImageService::allowPlainFilesForTests(true);
    $u = lStaff('r_staff1')['id'];
    $foto = iMake(['name' => 'Stok Berfoto'], upl(imgBytes('png', 500, 300, true)));
    $evilItem = lItem('Rekap Injeksi');
    rLoan($u, $evilItem, '=HYPERLINK("http://evil.example","klik")', 'returned', '2031-04-28 10:00:00',
        ['location' => '@SUM(A1)', 'team' => '+1+1', 'notes' => '=1+1', 'carrier_name_snapshot' => '-2+3']);
    Database::run("DELETE FROM activity_logs WHERE action = 'report.exported'");

    $period = ReportService::period('4', '2031');
    $file = ReportService::exportXlsx($period, ['id' => lAdmin()['id'], 'name' => 'Admin Uji'], ureq());
    eq('Laporan_Stok_HME_4_2031.xlsx', $file['filename']);
    eq("PK\x03\x04", substr($file['bytes'], 0, 4), 'berkas ZIP/XLSX');
    eq('Laporan_Stok_HME_all_semua.xlsx', ReportService::exportXlsx(ReportService::period('all', 'semua'), lAdmin())['filename']);
    eq('Laporan_Stok_HME_all_2031-2032.xlsx', ReportService::exportXlsx(ReportService::period('all', '2031-2032'), lAdmin())['filename']);

    $tmp = tempnam(sys_get_temp_dir(), 'hmexr') . '.xlsx';
    file_put_contents($tmp, $file['bytes']);
    try {
        $book = IOFactory::load($tmp);
        eq(['Laporan Stok', 'Transaksi Periode'], $book->getSheetNames());

        $st = $book->getSheet(0);
        eq(['No', 'ID Barang', 'Foto Alat', 'Nama Alat', 'Jumlah', 'Status', 'Peminjam Terakhir', 'Keterangan'], $st->rangeToArray('A1:H1')[0]);
        $stock = ReportService::stockRows();
        eq(count($stock) + 1, $st->getHighestDataRow(), 'satu baris per barang aktif');
        $byCode = [];
        for ($r = 2; $r <= $st->getHighestDataRow(); $r++) {
            $byCode[(string) $st->getCell("B{$r}")->getValue()] = $r;
        }
        $fr = $byCode[$foto['code']];
        $coords = array_map(fn($d) => $d->getCoordinates(), iterator_to_array($st->getDrawingCollection()));
        ok(in_array("C{$fr}", $coords, true), 'foto barang tersemat sebagai gambar di kolom C');
        $plain = $byCode[array_values(array_filter($stock, fn($x) => $x['photo_path'] === null))[0]['code']];
        eq('No Photo', $st->getCell("C{$plain}")->getValue());
        eq('n', $st->getCell("E{$fr}")->getDataType(), 'Jumlah numerik');
        eq(1, (int) $st->getCell("E{$fr}")->getValue());

        $tx = $book->getSheet(1);
        eq(['No', 'ID Pinjam', 'Tanggal Pinjam', 'Tanggal Kembali', 'Peminjam', 'ID Barang', 'Nama Alat', 'Lokasi', 'Tim', 'Keterangan', 'Status'], $tx->rangeToArray('A1:K1')[0]);
        $rows = ReportService::periodRows($period);
        eq(7, count($rows), 'April 2031: 6 transaksi dataset + 1 injeksi, tanpa yang dibatalkan');
        eq(count($rows) + 1, $tx->getHighestDataRow());
        $statuses = [];
        $evilRow = 0;
        for ($r = 2; $r <= $tx->getHighestDataRow(); $r++) {
            $statuses[] = (string) $tx->getCell("K{$r}")->getValue();
            if ($tx->getCell("J{$r}")->getValue() === '=1+1') {
                $evilRow = $r;
            }
        }
        ok(!in_array('Dibatalkan', $statuses, true) && in_array('Dipinjam', $statuses, true) && in_array('Menunggu', $statuses, true));
        ok($evilRow > 0, 'baris injeksi ditemukan');
        foreach (['E', 'G', 'H', 'I', 'J'] as $c) {
            eq('s', $tx->getCell("{$c}{$evilRow}")->getDataType(), "kolom {$c} harus string, bukan rumus");
        }
        eq('=HYPERLINK("http://evil.example","klik")', $tx->getCell("G{$evilRow}")->getValue());
        $d = $tx->getCell("C{$evilRow}");
        eq('n', $d->getDataType(), 'tanggal pinjam bertipe tanggal (angka Excel)');
        eq('28/04/2031 10:00', \PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString($d->getValue(), $tx->getStyle("C{$evilRow}")->getNumberFormat()->getFormatCode()));
        eq('Lokasi Uji', $tx->getCell('H2')->getValue());
        $book->disconnectWorksheets();
    } finally {
        @unlink($tmp);
    }
    eq(3, (int) Database::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'report.exported'"));
    ok(strpos((string) Database::value("SELECT after_json FROM activity_logs WHERE action = 'report.exported' ORDER BY id LIMIT 1"), 'April 2031') !== false);
});
