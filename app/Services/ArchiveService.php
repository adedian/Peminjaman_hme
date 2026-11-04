<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Arsip read-only hasil migrasi (admin):
 *  Q1  Catatan manual (Apr 2025–Apr 2026) — dapat dicari, difilter per tanda tanggal; TIDAK masuk rekap (BR-27).
 *  Q2  Register inventaris "Nama Barang Elektronik" — tombol "Jadikan Master Barang" membuka form barang terisi nama.
 *  §8  Laporan migrasi per baris (ok / gagal / duplikat / perlu diperiksa) + unduhan XLSX.
 */
final class ArchiveService
{
    public const FLAGS = ['ok', 'swapped_dm', 'needs_review', 'unparseable'];
    public const CATEGORIES = ['ok', 'failed', 'duplicate', 'needs_review'];
    private const MAX = 100;

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public static function manual(array $f, int $limit, int $offset): array
    {
        [$where, $params] = self::filters($f, ['item_text', 'carrier_text', 'location', 'team', 'notes', 'legacy_no'], 'parse_flag', self::FLAGS);
        $total = (int) Database::value('SELECT COUNT(*) FROM legacy_manual_loans' . $where, $params);
        $rows = Database::all(
            'SELECT id, source_row, legacy_no, item_text, carrier_text, borrowed_raw, borrowed_at, returned_raw, returned_at, return_note,
                    team, location, notes, extra_note, parse_flag FROM legacy_manual_loans' . $where . ' ORDER BY source_row LIMIT ? OFFSET ?',
            array_merge($params, [self::lim($limit), max(0, $offset)])
        );
        $flags = array_fill_keys(self::FLAGS, 0);
        foreach (Database::all('SELECT parse_flag, COUNT(*) AS n FROM legacy_manual_loans GROUP BY parse_flag') as $r) {
            $flags[(string) $r['parse_flag']] = (int) $r['n'];
        }
        return ['items' => array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['source_row'] = (int) $r['source_row'];
            return $r;
        }, $rows), 'total' => $total, 'flags' => $flags];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public static function register(array $f, int $limit, int $offset): array
    {
        $sections = array_column(Database::all('SELECT section, COUNT(*) AS n FROM legacy_inventory_register GROUP BY section ORDER BY MIN(source_row)'), 'n', 'section');
        [$where, $params] = self::filters($f, ['name', 'storage_location', 'current_position', 'note', 'condition_text'], 'section', array_keys($sections));
        $total = (int) Database::value('SELECT COUNT(*) FROM legacy_inventory_register' . $where, $params);
        $rows = Database::all(
            'SELECT id, section, source_row, no, name, qty, unit, storage_location, condition_text, current_position, note
             FROM legacy_inventory_register' . $where . ' ORDER BY source_row LIMIT ? OFFSET ?',
            array_merge($params, [self::lim($limit), max(0, $offset)])
        );
        // Nama yang sudah menjadi master barang (perbandingan tanpa huruf besar/kecil) → tombol konversi diganti penanda
        $names = array_flip(array_map('mb_strtolower', array_column(Database::all('SELECT name FROM items'), 'name')));
        return ['items' => array_map(static function (array $r) use ($names): array {
            $r['id'] = (int) $r['id'];
            $r['source_row'] = (int) $r['source_row'];
            $r['in_master'] = isset($names[mb_strtolower((string) $r['name'])]);
            return $r;
        }, $rows), 'total' => $total, 'sections' => array_map('intval', $sections)];
    }

    /** @return array<int,array<string,mixed>> */
    public static function runs(): array
    {
        return array_map(static function (array $r): array {
            $s = json_decode((string) $r['summary_json'], true) ?: [];
            $rec = $s['reconciliation'] ?? null;
            return [
                'id' => (int) $r['id'], 'mode' => (string) $r['mode'], 'status' => (string) $r['status'],
                'source_file' => (string) $r['source_file'], 'source_sha256' => (string) $r['source_sha256'],
                'started_at' => (string) $r['started_at'], 'finished_at' => $r['finished_at'],
                'counts' => $s['counts'] ?? null, 'stats' => $s['stats'] ?? null, 'error' => $s['error'] ?? null,
                'reconciliation' => $rec === null ? null : ['ok' => (bool) $rec['ok'], 'failed' => (int) $rec['failed'], 'checked_at' => $rec['checked_at'] ?? null, 'checks' => $rec['checks'] ?? []],
            ];
        }, Database::all('SELECT * FROM migration_runs ORDER BY id DESC LIMIT 50'));
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public static function issues(int $runId, array $f, int $limit, int $offset): array
    {
        if ((int) Database::value('SELECT COUNT(*) FROM migration_runs WHERE id = ?', [$runId]) === 0) {
            throw new HttpException(404, 'not_found', 'Laporan migrasi tidak ditemukan.');
        }
        [$where, $params] = self::filters($f, ['message', 'sheet'], 'category', self::CATEGORIES);
        $where = ($where === '' ? ' WHERE ' : $where . ' AND ') . 'run_id = ?';
        $params[] = $runId;
        $total = (int) Database::value('SELECT COUNT(*) FROM migration_issues' . $where, $params);
        $rows = Database::all('SELECT id, sheet, source_row, category, message, raw_json FROM migration_issues' . $where
            . " ORDER BY FIELD(category, 'failed', 'needs_review', 'duplicate', 'ok'), sheet, source_row, id LIMIT ? OFFSET ?",
            array_merge($params, [self::lim($limit), max(0, $offset)]));
        return ['items' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'sheet' => (string) $r['sheet'], 'row' => $r['source_row'] === null ? null : (int) $r['source_row'],
            'category' => (string) $r['category'], 'message' => (string) $r['message'],
            'raw' => $r['raw_json'] === null ? null : json_decode((string) $r['raw_json'], true),
        ], $rows), 'total' => $total];
    }

    /** XLSX laporan per baris. Teks ditulis sebagai string (anti formula injection). @return array{filename:string,bytes:string} */
    public static function issuesXlsx(int $runId): array
    {
        $all = self::issues($runId, [], 1, 0);   // validasi run ada
        unset($all);
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('Laporan Migrasi');
        $label = ['ok' => 'Berhasil', 'failed' => 'Gagal', 'duplicate' => 'Duplikat', 'needs_review' => 'Perlu diperiksa'];
        foreach (['Kategori', 'Sheet', 'Baris', 'Keterangan', 'Data'] as $i => $h) {
            $ws->setCellValueExplicit(chr(65 + $i) . '1', $h, DataType::TYPE_STRING);
        }
        $ws->getStyle('A1:E1')->getFont()->setBold(true);
        $ws->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F0');
        $r = 2;
        foreach (Database::all("SELECT sheet, source_row, category, message, raw_json FROM migration_issues WHERE run_id = ?
                                ORDER BY FIELD(category, 'failed', 'needs_review', 'duplicate', 'ok'), sheet, source_row, id", [$runId]) as $i) {
            $ws->setCellValueExplicit("A{$r}", $label[(string) $i['category']] ?? (string) $i['category'], DataType::TYPE_STRING);
            $ws->setCellValueExplicit("B{$r}", (string) $i['sheet'], DataType::TYPE_STRING);
            if ($i['source_row'] !== null) {
                $ws->setCellValueExplicit("C{$r}", (int) $i['source_row'], DataType::TYPE_NUMERIC);
            }
            $ws->setCellValueExplicit("D{$r}", (string) $i['message'], DataType::TYPE_STRING);
            $ws->setCellValueExplicit("E{$r}", (string) ($i['raw_json'] ?? ''), DataType::TYPE_STRING);
            $r++;
        }
        foreach (['A' => 16, 'B' => 26, 'C' => 8, 'D' => 90, 'E' => 70] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->freezePane('A2');
        $ws->setAutoFilter('A1:E' . max(1, $r - 1));
        $tmp = (string) tempnam(sys_get_temp_dir(), 'hmemr');
        try {
            (new Xlsx($book))->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
        return ['filename' => 'Laporan_Migrasi_HME_run' . $runId . '.xlsx', 'bytes' => $bytes];
    }

    /**
     * @param array<string,mixed> $f q (teks), filter kolom enum
     * @param array<int,string> $searchCols
     * @param array<int,string> $allowed nilai sah untuk filter enum
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function filters(array $f, array $searchCols, string $enumCol, array $allowed): array
    {
        $where = [];
        $params = [];
        $fields = [];
        foreach (['q', 'filter'] as $k) {
            if (isset($f[$k]) && !is_string($f[$k])) {
                $fields[$k] = 'Filter tidak valid.';
            }
        }
        if ($fields !== []) {
            throw new HttpException(422, 'validation_failed', 'Filter belum valid.', $fields);
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            if (mb_strlen($q) > 100) {
                throw new HttpException(422, 'validation_failed', 'Filter belum valid.', ['q' => 'Kata kunci terlalu panjang.']);
            }
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(' . implode(' OR ', array_map(static fn(string $c): string => "{$c} LIKE ?", $searchCols)) . ')';
            foreach ($searchCols as $unused) {
                $params[] = $like;
            }
        }
        $v = (string) ($f['filter'] ?? '');
        if ($v !== '') {
            if (!in_array($v, $allowed, true)) {
                throw new HttpException(422, 'validation_failed', 'Filter belum valid.', ['filter' => 'Pilihan filter tidak dikenal.']);
            }
            $where[] = "{$enumCol} = ?";
            $params[] = $v;
        }
        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    private static function lim(int $l): int
    {
        return max(1, min(self::MAX, $l));
    }
}
