<?php
declare(strict_types=1);

namespace App\Helpers;

/** Pembantu format tanggal berbahasa Indonesia (tanpa bergantung pada locale server). */
final class Idn
{
    private const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** "Kamis, 5 November 2026" */
    public static function dateLong(?int $ts = null): string
    {
        $ts = $ts ?? time();
        return self::DAYS[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . ' ' . self::MONTHS[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    private const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** 'Y-m-d H:i:s' → "05 Nov 2026 17.00" ('-' bila kosong/tak valid). */
    public static function dateTime(?string $dt): string
    {
        $t = $dt === null || $dt === '' ? false : strtotime($dt);
        return $t === false ? '-' : date('d', $t) . ' ' . self::MONTHS_SHORT[(int) date('n', $t) - 1] . ' ' . date('Y H.i', $t);
    }

    /** Selisih detik → "2 hari 3 jam" / "5 jam 10 mnt" / "12 mnt" (selalu positif). */
    public static function span(int $seconds): string
    {
        $a = abs($seconds);
        $d = intdiv($a, 86400);
        $h = intdiv($a % 86400, 3600);
        $m = intdiv($a % 3600, 60);
        if ($d > 0) {
            return $d . ' hari ' . $h . ' jam';
        }
        return $h > 0 ? $h . ' jam ' . $m . ' mnt' : max($m, 1) . ' mnt';
    }
}
