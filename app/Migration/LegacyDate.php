<?php
declare(strict_types=1);

namespace App\Migration;

use DateTime;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

/**
 * Parser tanggal data lama (§8 rencana).
 *  - Transaksi aplikasi: teks "dd/mm/yyyy HH.MM" (dibuat Apps Script, format Indonesia).
 *  - Data manual: teks "d/m/yyyy[ H(:|.|;)MM]" ATAU sel tanggal hasil salah-konversi Google Sheets (Sheets membaca
 *    "05/04/2025" sebagai 4 Mei) → bulan & hari DITUKAR, ditandai swapped_dm.
 *  - Sel PQA: tanggal asli (serial Excel) tanpa salah konversi.
 */
final class LegacyDate
{
    private const TZ = 'Asia/Jakarta';

    /** "dd/mm/yyyy HH.MM" (format aplikasi lama) → "Y-m-d H:i:s" atau null. */
    public static function appText(string $s): ?string
    {
        if (!preg_match('/^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2})\.(\d{2})\s*$/', $s, $m)) {
            return null;
        }
        return self::build((int) $m[3], (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5]);
    }

    /** Teks tanggal Indonesia bebas: "d/m/yyyy", opsional jam "H:MM" / "H.MM" / "H;MM". */
    public static function freeText(string $s): ?string
    {
        if (!preg_match('/^\s*(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[\s;,]+(\d{1,2})\s*[:.;]\s*(\d{2}))?\s*$/', $s, $m)) {
            return null;
        }
        $h = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $i = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        return self::build((int) $m[3], (int) $m[2], (int) $m[1], $h, $i);
    }

    /** Serial Excel → waktu dinding Asia/Jakarta (tanpa penukaran). */
    public static function serial(float $serial): ?string
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $d = XlsDate::excelToDateTimeObject($serial, new DateTimeZone(self::TZ));
        // dibulatkan ke detik terdekat (serial menyimpan pecahan hari)
        $ts = (int) round((float) $d->format('U.u'));
        return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i:s');
    }

    /** Serial Excel hasil salah-konversi Sheets → tanggal sebenarnya (bulan↔hari ditukar). */
    public static function serialSwapped(float $serial): ?string
    {
        $s = self::serial($serial);
        if ($s === null) {
            return null;
        }
        [$date, $time] = explode(' ', $s);
        [$y, $mo, $d] = array_map('intval', explode('-', $date));
        if ($d > 12) {
            return null;    // tidak mungkin hasil penukaran (Sheets hanya mengonversi bila keduanya ≤ 12)
        }
        [$h, $i] = array_map('intval', explode(':', $time));
        return self::build($y, $d, $mo, $h, $i);
    }

    public static function days(string $from, string $to): float
    {
        return (strtotime($to) - strtotime($from)) / 86400;
    }

    private static function build(int $y, int $mo, int $d, int $h = 0, int $i = 0): ?string
    {
        if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y) || $h > 23 || $i > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $h, $i);
    }
}
