<?php
declare(strict_types=1);

namespace App\Migration;

use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

/**
 * Port BARIS-PER-BARIS algoritma sistem lama (Code.gs + index.html), dipakai sebagai pembanding paritas:
 * angka sistem baru harus identik dengan yang akan dihitung sistem lama dari sheet yang sama.
 */
final class LegacyReference
{
    /**
     * getMonthlyStats(m, y) + pengurutan ranking di klien (sort b[1]-a[1], stabil).
     * $rows: [['date' => sel D {v,t}, 'name' => B, 'carrier' => C, 'status' => I, 'id' => J], ...] dalam urutan sheet.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{total:int,approved:int,returned:int,pending:int,rejected:int,topItems:array<int,array{0:string,1:int}>,activeList:array<int,array{namaAlat:string,peminjam:string}>}
     */
    public static function monthlyStats(array $rows, string $m, string $y): array
    {
        $s = ['total' => 0, 'approved' => 0, 'returned' => 0, 'pending' => 0, 'rejected' => 0, 'topItems' => [], 'activeList' => []];
        $yStr = strtolower(trim($y));
        $isAllYears = $yStr === 'semua' || $yStr === 'all' || $yStr === '';
        $yearStart = 0;
        $yearEnd = 9999;
        if (!$isAllYears) {
            if (strpos($yStr, '-') !== false) {
                $pts = explode('-', $yStr);
                $yearStart = (int) trim($pts[0]);
                $yearEnd = (int) trim($pts[1]);
            } else {
                $yearStart = $yearEnd = (int) $yStr;
            }
        }
        $isAllMonths = strtolower($m) === 'all';
        foreach ($rows as $row) {
            $tgl = $row['date'];
            if ($tgl === null || $tgl['v'] === null || (string) $row['id'] === '') {
                continue;
            }
            $mm = -1;
            $yy = -1;
            if ($tgl['t'] === 'n') {                          // `tgl instanceof Date`
                $d = XlsDate::excelToDateTimeObject((float) $tgl['v']);
                $mm = (int) $d->format('n');
                $yy = (int) $d->format('Y');
            } else {
                $p = explode('/', explode(' ', (string) $tgl['v'])[0]);
                if (count($p) >= 3) {
                    $mm = (int) $p[1];
                    $yy = (int) $p[2];
                }
            }
            if (($isAllMonths || $mm === (int) $m) && ($isAllYears || ($yy >= $yearStart && $yy <= $yearEnd))) {
                $s['total']++;
                $st = (string) $row['status'];
                if ($st === 'approved') {
                    $s['approved']++;
                    $s['activeList'][] = ['namaAlat' => (string) $row['name'], 'peminjam' => (string) $row['carrier']];
                }
                if ($st === 'returned') { $s['returned']++; }
                if ($st === 'pending') { $s['pending']++; }
                if ($st === 'rejected') { $s['rejected']++; }
                $k = 'k:' . $row['name'];
                $s['topItems'][$k] = [(string) $row['name'], ($s['topItems'][$k][1] ?? 0) + 1];
            }
        }
        $sorted = array_values($s['topItems']);
        usort($sorted, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        $s['topItems'] = $sorted;
        return $s;
    }

    /** Baris transaksi dari sumber dalam bentuk yang dipakai monthlyStats. @return array<int,array<string,mixed>> */
    public static function loanRows(LegacySource $src): array
    {
        $out = [];
        foreach ($src->rows(LegacySource::SHEET_LOANS) as $row) {
            $out[] = ['date' => $row['D'], 'name' => LegacySource::text($row, 'B'), 'carrier' => LegacySource::text($row, 'C'),
                      'status' => LegacySource::text($row, 'I'), 'id' => LegacySource::text($row, 'J')];
        }
        return $out;
    }

    /**
     * generateExcelReport(): kolom Jumlah/Status/Peminjam Terakhir/Keterangan per ID barang (urutan Master_Barang).
     * Catatan: algoritma lama memakai transaksi terakhir APA PUN statusnya (temuan F2) — dipertahankan di sini apa adanya.
     *
     * @return array<string,array{qty:int,status:string,borrower:string,note:string}>
     */
    public static function stockReport(LegacySource $src): array
    {
        $last = [];
        foreach ($src->rows(LegacySource::SHEET_LOANS) as $row) {
            if (LegacySource::text($row, 'J') === '') {
                continue;
            }
            $last[LegacySource::text($row, 'L')] = ['peminjam' => LegacySource::text($row, 'C'), 'lokasi' => LegacySource::text($row, 'G'),
                                                    'ket' => LegacySource::text($row, 'H'), 'status' => LegacySource::text($row, 'I')];
        }
        $out = [];
        foreach ($src->rows(LegacySource::SHEET_ITEMS) as $row) {
            $id = LegacySource::text($row, 'A');
            if ($id === '') {
                continue;
            }
            $status = LegacySource::text($row, 'E') !== '' ? LegacySource::text($row, 'E') : 'Tersedia';
            $peminjam = '-';
            if (isset($last[$id])) {
                $peminjam = $last[$id]['peminjam'];
                $ket = $status === 'Dipinjam'
                    ? 'Dibawa ke ' . $last[$id]['lokasi'] . ($last[$id]['ket'] !== '' ? ' (Ket: ' . $last[$id]['ket'] . ')' : '')
                    : 'Di Gudang (Terakhir kembali dari ' . $last[$id]['lokasi'] . ')';
            } else {
                $ket = 'Di Gudang';
            }
            $out[$id] = ['qty' => $status === 'Dipinjam' ? 0 : 1, 'status' => $status, 'borrower' => $peminjam, 'note' => $ket];
        }
        return $out;
    }
}
