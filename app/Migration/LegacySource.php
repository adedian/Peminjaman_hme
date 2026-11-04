<?php
declare(strict_types=1);

namespace App\Migration;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Pembaca ekspor XLSX spreadsheet lama (sumber Apps Script). HANYA MEMBACA — berkas sumber tidak pernah diubah.
 * Akses kolom berdasarkan POSISI (seperti Code.gs), bukan nama header.
 *
 * Setiap sel dikembalikan sebagai ['v' => nilai, 't' => 's'|'n'|'b'|'null'] agar parser bisa membedakan teks
 * "05/04/2025" dengan sel tanggal hasil konversi otomatis Google Sheets (angka serial Excel).
 */
final class LegacySource
{
    public const SHEET_USERS = 'Data_Users';
    public const SHEET_ITEMS = 'Master_Barang';
    public const SHEET_LOANS = 'Data Barang Masuk-Keluar';
    public const SHEET_PQA = 'Data_PQA';
    public const SHEET_REGISTER = 'Nama Barang Elektronik';

    private string $path;
    private string $sha256;
    /** @var array<string,array<int,array<string,array{v:mixed,t:string}>>> */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('Berkas sumber tidak ditemukan atau tidak dapat dibaca: ' . $path);
        }
        $this->path = $real;
        $this->sha256 = (string) hash_file('sha256', $real);

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($real);
        foreach ([self::SHEET_USERS, self::SHEET_ITEMS, self::SHEET_LOANS, self::SHEET_PQA, self::SHEET_REGISTER] as $name) {
            $ws = $book->getSheetByName($name);
            if ($ws === null) {
                if ($name === self::SHEET_REGISTER) {
                    $this->sheets[$name] = [];   // sheet arsip opsional
                    continue;
                }
                throw new RuntimeException("Sheet \"{$name}\" tidak ada di berkas sumber.");
            }
            $this->sheets[$name] = self::readSheet($ws);
        }
        $book->disconnectWorksheets();
    }

    public function path(): string { return $this->path; }
    public function sha256(): string { return $this->sha256; }

    /**
     * Baris data (tanpa header baris 1), dikunci nomor baris sheet. Baris kosong total dilewati.
     *
     * @return array<int,array<string,array{v:mixed,t:string}>>
     */
    public function rows(string $sheet): array
    {
        return $this->sheets[$sheet] ?? [];
    }

    /** Nilai sel sebagai teks (angka bulat tanpa ".0"). @param array<string,array{v:mixed,t:string}> $row */
    public static function text(array $row, string $col): string
    {
        $c = $row[$col] ?? null;
        if ($c === null || $c['v'] === null) {
            return '';
        }
        $v = $c['v'];
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return (string) $v;
    }

    /** @param array<string,array{v:mixed,t:string}> $row */
    public static function isNumeric(array $row, string $col): bool
    {
        return isset($row[$col]) && $row[$col]['t'] === DataType::TYPE_NUMERIC && is_numeric($row[$col]['v']);
    }

    /** @return array<int,array<string,array{v:mixed,t:string}>> */
    private static function readSheet(Worksheet $ws): array
    {
        $out = [];
        $max = $ws->getHighestDataRow();
        for ($r = 2; $r <= $max; $r++) {
            $row = [];
            $empty = true;
            foreach (range('A', 'O') as $col) {
                $cell = $ws->getCell($col . $r);
                $v = $cell->getValue();
                if (is_string($v)) {
                    $v = str_replace("\r\n", "\n", $v);
                }
                $t = $v === null || $v === '' ? 'null' : $cell->getDataType();
                if ($t !== 'null') {
                    $empty = false;
                }
                $row[$col] = ['v' => $v === '' ? null : $v, 't' => $t];
            }
            if (!$empty) {
                $out[$r] = $row;
            }
        }
        return $out;
    }
}
