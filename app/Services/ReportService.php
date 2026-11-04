<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use DateTime;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Rekap & laporan Excel (admin).
 *
 *  BR-26  Rekap = transaksi aplikasi (tabel loans; catatan manual ada di arsip terpisah → BR-27) yang difilter
 *         berdasarkan bulan/tahun TANGGAL PINJAM. Bulan: 1..12 atau semua. Tahun: tunggal, rentang "2025-2026",
 *         atau semua. Menghitung total/approved/returned/pending/rejected, ranking per NAMA ALAT (snapshot saat
 *         pinjam, persis sistem lama — temuan F3 sengaja dipertahankan demi paritas) dan daftar barang aktif.
 *  Q5     Loan `cancelled` tidak dihitung sama sekali (tidak ada di data lama).
 *  BR-28/Q9  Excel: sheet "Laporan Stok" (snapshot master barang, kolom sama dengan laporan lama; "Peminjam Terakhir"
 *         hanya dari transaksi approved/returned → perbaikan F2) + sheet "Transaksi Periode" yang mengikuti filter
 *         (perbaikan F1). Dibuat on-the-fly dan langsung diunduh, tidak ada berkas publik (S7).
 *
 * Paritas dengan getMonthlyStats() lama: urutan baris = urutan sheet (SHEET_ORDER), ranking diurutkan menurun dengan
 * urutan kemunculan pertama untuk nilai seri (sort stabil), pengelompokan nama memakai string PERSIS (case &
 * spasi berpengaruh) — karena itu dikelompokkan di PHP, bukan GROUP BY (collation MySQL tidak peka huruf).
 */
final class ReportService
{
    public const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    private const YEAR_MIN = 2000;
    private const YEAR_MAX = 2100;
    private const STATUS_LABEL = ['pending' => 'Menunggu', 'approved' => 'Dipinjam', 'returned' => 'Selesai', 'rejected' => 'Ditolak'];
    private const ROW_HEIGHT_PT = 52.5;   // ≈ 70 px, sama dengan laporan lama
    private const PHOTO_PX = 64;
    /**
     * Urutan "baris sheet" (paritas sistem lama): data hasil migrasi menurut nomor baris sheet aslinya (baris satu pengajuan
     * tidak selalu berurutan nomor), lalu transaksi yang dibuat di sistem baru menurut nomor urut — sama seperti baris
     * baru yang dulu ditambahkan di bawah sheet.
     */
    private const SHEET_ORDER = 'l.legacy_row IS NULL, l.legacy_row, l.seq_no, l.id';

    // ======================================================================================
    //  Periode
    // ======================================================================================

    /**
     * Normalisasi filter. Nilai "semua" mengikuti sistem lama: bulan "all", tahun ""/"semua"/"all".
     *
     * @param mixed $month
     * @param mixed $year
     * @return array{month:?int,year_from:?int,year_to:?int,label:string,month_param:string,year_param:string}
     */
    public static function period($month, $year): array
    {
        $fields = [];
        $m = null;
        if ($month !== null && !is_string($month) && !is_int($month)) {
            $fields['month'] = 'Bulan tidak valid.';
        } else {
            $ms = strtolower(trim((string) ($month ?? '')));
            if ($ms !== '' && $ms !== 'all' && $ms !== 'semua') {
                if (!ctype_digit($ms) || (int) $ms < 1 || (int) $ms > 12) {
                    $fields['month'] = 'Pilih bulan 1–12 atau semua bulan.';
                } else {
                    $m = (int) $ms;
                }
            }
        }

        $from = $to = null;
        if ($year !== null && !is_string($year) && !is_int($year)) {
            $fields['year'] = 'Tahun tidak valid.';
        } else {
            $ys = strtolower(trim((string) ($year ?? '')));
            if ($ys !== '' && $ys !== 'semua' && $ys !== 'all') {
                if (!preg_match('/^(\d{4})(?:\s*-\s*(\d{4}))?$/', $ys, $mm)) {
                    $fields['year'] = 'Tulis tahun seperti 2026 atau rentang 2025-2026.';
                } else {
                    $from = (int) $mm[1];
                    $to = isset($mm[2]) && $mm[2] !== '' ? (int) $mm[2] : $from;
                    if ($from < self::YEAR_MIN || $to > self::YEAR_MAX) {
                        $fields['year'] = 'Tahun harus di antara ' . self::YEAR_MIN . ' dan ' . self::YEAR_MAX . '.';
                    } elseif ($from > $to) {
                        $fields['year'] = 'Tahun awal rentang tidak boleh lebih besar dari tahun akhir.';
                    }
                }
            }
        }
        if ($fields !== []) {
            throw new HttpException(422, 'validation_failed', 'Filter rekap belum valid.', $fields);
        }

        $yearParam = $from === null ? 'semua' : ($from === $to ? (string) $from : $from . '-' . $to);
        $yearLabel = $from === null ? '' : ($from === $to ? (string) $from : $from . '–' . $to);
        if ($m === null && $from === null) {
            $label = 'Semua data';
        } elseif ($m === null) {
            $label = 'Semua bulan ' . $yearLabel;
        } elseif ($from === null) {
            $label = self::MONTHS[$m - 1] . ' (semua tahun)';
        } else {
            $label = self::MONTHS[$m - 1] . ' ' . $yearLabel;
        }
        return [
            'month' => $m, 'year_from' => $from, 'year_to' => $to, 'label' => $label,
            'month_param' => $m === null ? 'all' : (string) $m, 'year_param' => $yearParam,
        ];
    }

    // ======================================================================================
    //  Rekap (BR-26)
    // ======================================================================================

    /**
     * @param array<string,mixed> $period hasil period()
     * @return array<string,mixed>
     */
    public static function summary(array $period): array
    {
        $s = ['total' => 0, 'approved' => 0, 'returned' => 0, 'pending' => 0, 'rejected' => 0];
        $top = [];      // "k:<nama persis>" => [nama, jumlah] — awalan "k:" mencegah nama numerik menjadi kunci int
        $active = [];
        foreach (self::periodLoans($period) as $r) {
            $s['total']++;
            $st = (string) $r['status'];
            if (isset($s[$st])) {
                $s[$st]++;
            }
            if ($st === 'approved') {
                $active[] = [
                    'item_name'   => (string) $r['item_name_snapshot'],
                    'borrower'    => (string) $r['carrier_name_snapshot'],
                    'item_code'   => (string) $r['item_code'],
                    'location'    => (string) $r['location'],
                    'borrowed_at' => (string) $r['borrowed_at'],
                ];
            }
            $k = 'k:' . $r['item_name_snapshot'];
            if (!isset($top[$k])) {
                $top[$k] = ['name' => (string) $r['item_name_snapshot'], 'count' => 0];
            }
            $top[$k]['count']++;
        }
        $top = array_values($top);
        usort($top, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);   // stabil di PHP 8

        return [
            'period'    => self::publicPeriod($period),
            'total'     => $s['total'],
            'approved'  => $s['approved'],
            'returned'  => $s['returned'],
            'pending'   => $s['pending'],
            'rejected'  => $s['rejected'],
            'top_items' => $top,
            'active'    => $active,
        ];
    }

    // ======================================================================================
    //  Laporan Excel (BR-28 / Q9)
    // ======================================================================================

    /**
     * Membuat XLSX di memori.
     *
     * @param array<string,mixed> $period
     * @param array<string,mixed> $admin
     * @return array{filename:string,bytes:string}
     */
    public static function exportXlsx(array $period, array $admin, ?Request $request = null): array
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator('Sistem Peminjaman HME')
            ->setLastModifiedBy((string) ($admin['name'] ?? 'Admin'))
            ->setTitle('Laporan Stok HME')
            ->setDescription('Periode transaksi: ' . $period['label']);

        $stock = $book->getActiveSheet();
        $stock->setTitle('Laporan Stok');
        $stockRows = self::fillStockSheet($stock);

        $tx = $book->createSheet();
        $tx->setTitle('Transaksi Periode');
        $txRows = self::fillPeriodSheet($tx, $period);

        $book->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'hmex');
        if ($tmp === false) {
            throw new HttpException(500, 'export_failed', 'Laporan tidak dapat dibuat.');
        }
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }

        $filename = 'Laporan_Stok_HME_' . $period['month_param'] . '_' . $period['year_param'] . '.xlsx';
        AuditService::log('report.exported', isset($admin['id']) ? (int) $admin['id'] : null, 'report', null, null,
            ['period' => $period['label'], 'stock_rows' => $stockRows, 'period_rows' => $txRows], $request);
        return ['filename' => $filename, 'bytes' => $bytes];
    }

    /**
     * Baris sheet "Laporan Stok" (dipisah agar logika kolom dapat diuji tanpa membaca XLSX).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stockRows(): array
    {
        $items = Database::all(
            'SELECT id, code, name, photo_path, photo_legacy_url, master_status FROM items WHERE is_active = 1 ORDER BY id'
        );
        // Transaksi terakhir per barang — hanya approved/returned (F2). "Terakhir" = baris terbawah sheet (lihat SHEET_ORDER).
        $last = [];
        foreach (Database::all(
            "SELECT l.item_id, l.carrier_name_snapshot, l.location, l.notes FROM loans l
             WHERE l.status IN ('approved','returned') ORDER BY " . self::SHEET_ORDER
        ) as $r) {
            $last[(int) $r['item_id']] = $r;   // baris berikutnya menimpa → yang tersisa adalah yang terakhir
        }

        $out = [];
        $no = 1;
        foreach ($items as $it) {
            $status = (string) $it['master_status'];
            $tx = $last[(int) $it['id']] ?? null;
            $borrower = '-';
            if ($tx === null) {
                $note = 'Di Gudang';
            } else {
                $borrower = (string) $tx['carrier_name_snapshot'];
                $ket = $tx['notes'] === null ? '' : (string) $tx['notes'];
                $note = $status === 'Dipinjam'
                    ? 'Dibawa ke ' . $tx['location'] . ($ket !== '' ? ' (Ket: ' . $ket . ')' : '')
                    : 'Di Gudang (Terakhir kembali dari ' . $tx['location'] . ')';
            }
            $out[] = [
                'no'         => $no++,
                'code'       => (string) $it['code'],
                'photo_path' => $it['photo_path'] === null ? null : (string) $it['photo_path'],
                'photo_url'  => $it['photo_legacy_url'] === null ? null : (string) $it['photo_legacy_url'],
                'name'       => (string) $it['name'],
                'qty'        => $status === 'Dipinjam' ? 0 : 1,
                'status'     => $status,
                'borrower'   => $borrower,
                'note'       => $note,
            ];
        }
        return $out;
    }

    /**
     * Baris sheet "Transaksi Periode".
     *
     * @param array<string,mixed> $period
     * @return array<int,array<string,mixed>>
     */
    public static function periodRows(array $period): array
    {
        $out = [];
        $no = 1;
        foreach (self::periodLoans($period) as $r) {
            $out[] = [
                'no'          => $no++,
                'loan_code'   => (string) ($r['legacy_id'] ?? ''),
                'borrowed_at' => (string) $r['borrowed_at'],
                'returned_at' => $r['returned_at'] === null ? null : (string) $r['returned_at'],
                'borrower'    => (string) $r['carrier_name_snapshot'],
                'item_code'   => (string) $r['item_code'],
                'item_name'   => (string) $r['item_name_snapshot'],
                'location'    => (string) $r['location'],
                'team'        => (string) ($r['team'] ?? ''),
                'notes'       => (string) ($r['notes'] ?? ''),
                'status'      => self::STATUS_LABEL[(string) $r['status']] ?? (string) $r['status'],
            ];
        }
        return $out;
    }

    // ======================================================================================
    //  Internal
    // ======================================================================================

    /**
     * @param array<string,mixed> $period
     * @return array<int,array<string,mixed>>
     */
    private static function periodLoans(array $period): array
    {
        $where = ["l.status <> 'cancelled'"];
        $params = [];
        if ($period['month'] !== null) {
            $where[] = 'MONTH(l.borrowed_at) = ?';
            $params[] = (int) $period['month'];
        }
        if ($period['year_from'] !== null) {
            $where[] = 'YEAR(l.borrowed_at) BETWEEN ? AND ?';
            $params[] = (int) $period['year_from'];
            $params[] = (int) $period['year_to'];
        }
        return Database::all(
            'SELECT l.id, l.seq_no, l.legacy_id, l.status, l.item_name_snapshot, l.carrier_name_snapshot, l.borrowed_at, l.returned_at,
                    l.team, l.location, l.notes, i.code AS item_code
             FROM loans l JOIN items i ON i.id = l.item_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . self::SHEET_ORDER,
            $params
        );
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private static function publicPeriod(array $p): array
    {
        return ['month' => $p['month'], 'year_from' => $p['year_from'], 'year_to' => $p['year_to'], 'label' => $p['label'],
                'month_param' => $p['month_param'], 'year_param' => $p['year_param']];
    }

    /** Teks SELALU ditulis eksplisit sebagai string: input pengguna seperti "=HYPERLINK(...)" tidak boleh menjadi rumus. */
    private static function text(Worksheet $ws, string $cell, string $value): void
    {
        $ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    /** @param array<int,string> $headers */
    private static function header(Worksheet $ws, array $headers): void
    {
        foreach ($headers as $i => $h) {
            self::text($ws, self::col($i + 1) . '1', $h);
        }
        $range = 'A1:' . self::col(count($headers)) . '1';
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
        $ws->freezePane('A2');
    }

    private static function col(int $n): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    }

    private static function fillStockSheet(Worksheet $ws): int
    {
        self::header($ws, ['No', 'ID Barang', 'Foto Alat', 'Nama Alat', 'Jumlah', 'Status', 'Peminjam Terakhir', 'Keterangan']);
        $rows = self::stockRows();
        $r = 2;
        foreach ($rows as $row) {
            $ws->setCellValueExplicit("A{$r}", $row['no'], DataType::TYPE_NUMERIC);
            self::text($ws, "B{$r}", $row['code']);
            if (!self::embedPhoto($ws, "C{$r}", $row['photo_path'], $row['name'])) {
                $url = $row['photo_url'];
                if ($url !== null && preg_match('#^https://#i', $url)) {
                    self::text($ws, "C{$r}", 'Lihat foto');
                    $ws->getCell("C{$r}")->getHyperlink()->setUrl($url);
                } else {
                    self::text($ws, "C{$r}", 'No Photo');
                }
            }
            self::text($ws, "D{$r}", $row['name']);
            $ws->setCellValueExplicit("E{$r}", $row['qty'], DataType::TYPE_NUMERIC);
            self::text($ws, "F{$r}", $row['status']);
            self::text($ws, "G{$r}", $row['borrower']);
            self::text($ws, "H{$r}", $row['note']);
            $ws->getRowDimension($r)->setRowHeight(self::ROW_HEIGHT_PT);
            $r++;
        }
        foreach (['A' => 6, 'B' => 14, 'C' => 17, 'D' => 26, 'E' => 9, 'F' => 12, 'G' => 22, 'H' => 45] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        if ($r > 2) {
            $ws->getStyle('A2:H' . ($r - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        }
        return count($rows);
    }

    /** @param array<string,mixed> $period */
    private static function fillPeriodSheet(Worksheet $ws, array $period): int
    {
        self::header($ws, ['No', 'ID Pinjam', 'Tanggal Pinjam', 'Tanggal Kembali', 'Peminjam', 'ID Barang', 'Nama Alat', 'Lokasi', 'Tim', 'Keterangan', 'Status']);
        $rows = self::periodRows($period);
        $r = 2;
        foreach ($rows as $row) {
            $ws->setCellValueExplicit("A{$r}", $row['no'], DataType::TYPE_NUMERIC);
            self::text($ws, "B{$r}", $row['loan_code']);
            self::date($ws, "C{$r}", $row['borrowed_at']);
            self::date($ws, "D{$r}", $row['returned_at']);
            self::text($ws, "E{$r}", $row['borrower']);
            self::text($ws, "F{$r}", $row['item_code']);
            self::text($ws, "G{$r}", $row['item_name']);
            self::text($ws, "H{$r}", $row['location']);
            self::text($ws, "I{$r}", $row['team']);
            self::text($ws, "J{$r}", $row['notes']);
            self::text($ws, "K{$r}", $row['status']);
            $r++;
        }
        foreach (['A' => 6, 'B' => 22, 'C' => 17, 'D' => 17, 'E' => 20, 'F' => 12, 'G' => 26, 'H' => 26, 'I' => 20, 'J' => 32, 'K' => 11] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->setAutoFilter('A1:K' . max(1, $r - 1));
        return count($rows);
    }

    private static function date(Worksheet $ws, string $cell, ?string $value): void
    {
        $d = $value === null ? false : DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($d === false) {
            self::text($ws, $cell, '');
            return;
        }
        $ws->setCellValueExplicit($cell, XlsDate::PHPToExcel($d), DataType::TYPE_NUMERIC);
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
    }

    /** Foto disematkan sebagai gambar (bukan tautan publik). Gagal membaca berkas → false (dipakai teks pengganti). */
    private static function embedPhoto(Worksheet $ws, string $cell, ?string $photo, string $name): bool
    {
        if ($photo === null || !ImageService::isValidName($photo)) {
            return false;
        }
        $file = null;
        foreach ([ImageService::thumbName($photo), $photo] as $n) {
            $p = ImageService::path($n);
            if (is_file($p)) {
                $file = $p;
                break;
            }
        }
        if ($file === null) {
            return false;
        }
        $raw = @file_get_contents($file);
        $src = $raw === false ? false : @imagecreatefromstring($raw);
        if ($src === false) {
            return false;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = self::PHOTO_PX * 2 / max($w, $h, 1);   // 2× agar tajam di layar HiDPI
        $nw = max(1, (int) round($w * min(1, $scale)));
        $nh = max(1, (int) round($h * min(1, $scale)));
        $img = imagecreatetruecolor($nw, $nh);
        if ($img === false) {
            imagedestroy($src);
            return false;
        }
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));   // latar putih untuk PNG transparan
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $d = new MemoryDrawing();
        $d->setName($name);
        $d->setDescription($name);
        $d->setImageResource($img);
        $d->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
        $d->setMimeType(MemoryDrawing::MIMETYPE_JPEG);
        $d->setResizeProportional(true);
        if ($nw >= $nh) {
            $d->setWidth(self::PHOTO_PX);
        } else {
            $d->setHeight(self::PHOTO_PX);
        }
        $d->setCoordinates($cell);
        $d->setOffsetX(8);
        $d->setOffsetY(3);
        $d->setWorksheet($ws);
        return true;
    }
}
