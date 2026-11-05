<?php
declare(strict_types=1);

/**
 * Membuat ulang seluruh ikon aplikasi/PWA dari logo heksagon HME (public/assets/img/logo-hme.png).
 * Dijalankan manual saat logo berubah; hasilnya (PNG) ikut di repositori sehingga server produksi tidak butuh GD/font.
 *
 *   php tools/make-icons.php --font=C:/Windows/Fonts/segoeuib.ttf
 *
 * Hasil di public/assets/img/:
 *   logo-mark.png        heksagon HME terpangkas rapi (transparan) — dipakai di header, sidebar, halaman masuk
 *   icon-192.png, icon-512.png            ikon "any" (sudut membulat) bertuliskan PEMINJAMAN + logo
 *   icon-maskable-512.png                 ikon maskable (isi di zona aman 80%)
 *   apple-touch-icon.png (180)            ikon layar utama iOS (tanpa transparansi)
 *   favicon-32.png                        favicon (heksagon saja)
 *   badge-96.png                          lencana notifikasi Android (putih, transparan)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('imagettftext')) {
    fwrite(STDERR, "Butuh ekstensi GD dengan FreeType.\n");
    exit(1);
}

$font = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--font=') === 0) {
        $font = substr($a, 7);
    }
}
if ($font === null || !is_file($font)) {
    fwrite(STDERR, "Gunakan --font=<berkas .ttf tebal>, mis. C:/Windows/Fonts/segoeuib.ttf\n");
    exit(1);
}

$dir = dirname(__DIR__) . '/public/assets/img';
$src = imagecreatefrompng($dir . '/logo-hme.png');
imagealphablending($src, false);
imagesavealpha($src, true);
$w = imagesx($src);
$h = imagesy($src);

// 1) Pangkas ke kotak pembatas piksel yang tidak transparan (logo asli punya tepi kosong yang tidak simetris)
$minX = $w; $minY = $h; $maxX = 0; $maxY = 0;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $a = (imagecolorat($src, $x, $y) >> 24) & 0x7F;   // 0 = buram, 127 = transparan
        if ($a < 100) {
            $minX = min($minX, $x); $maxX = max($maxX, $x); $minY = min($minY, $y); $maxY = max($maxY, $y);
        }
    }
}
$cw = $maxX - $minX + 1;
$ch = $maxY - $minY + 1;
$mark = imagecreatetruecolor($cw, $ch);
imagealphablending($mark, false);
imagesavealpha($mark, true);
imagefill($mark, 0, 0, imagecolorallocatealpha($mark, 0, 0, 0, 127));
imagecopy($mark, $src, 0, 0, $minX, $minY, $cw, $ch);
echo "Logo terpangkas: {$cw}x{$ch} (dari {$w}x{$h})\n";

function save(\GdImage $im, string $path): void
{
    imagesavealpha($im, true);
    imagepng($im, $path, 9);
    echo '  ' . basename($path) . ' ' . imagesx($im) . 'x' . imagesy($im) . "\n";
}

function canvas(int $s, bool $transparent): \GdImage
{
    $im = imagecreatetruecolor($s, $s);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, $transparent ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 20, 20, 20));
    imagealphablending($im, true);
    return $im;
}

/** Salin $mark ke tengah-atas kanvas dengan lebar $mw. */
function place(\GdImage $dst, \GdImage $mark, int $cx, int $top, int $mw): void
{
    $mh = (int) round(imagesy($mark) * $mw / imagesx($mark));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $mark, $cx - intdiv($mw, 2), $top, 0, 0, $mw, $mh, imagesx($mark), imagesy($mark));
}

/** Teks dengan jarak huruf (letter-spacing), rata tengah. */
function spaced(\GdImage $im, string $font, float $size, string $text, int $cx, int $baseY, int $color, float $tracking): void
{
    $widths = [];
    $total = 0.0;
    $bw = static function (string $t) use ($size, $font): float {
        $bb = imagettfbbox($size, 0, $font, $t);
        return (float) ($bb[2] - $bb[0]);
    };
    $ii = $bw('II');
    foreach (str_split($text) as $ch) {
        // maju-huruf sebenarnya (termasuk bearing): lebar "I<huruf>I" dikurangi lebar "II"
        $adv = $bw('I' . $ch . 'I') - $ii + $tracking;
        $widths[] = $adv;
        $total += $adv;
    }
    $total -= $tracking;
    $x = $cx - $total / 2;
    foreach (str_split($text) as $i => $ch) {
        imagettftext($im, $size, 0, (int) round($x), $baseY, $color, $font, $ch);
        $x += $widths[$i];
    }
}

/** Satu ikon: heksagon + tulisan PEMINJAMAN. $safe = fraksi zona aman (maskable memakai 0.8). */
function icon(int $s, \GdImage $mark, string $font, bool $rounded, float $safe): \GdImage
{
    $im = canvas($s, $rounded);
    if ($rounded) {                                   // sudut membulat: timpa pojok dengan transparan
        $r = (int) round($s * 0.22);
        $bg = imagecolorallocate($im, 20, 20, 20);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, $r, 0, $s - $r - 1, $s - 1, $bg);
        imagefilledrectangle($im, 0, $r, $s - 1, $s - $r - 1, $bg);
        foreach ([[$r, $r], [$s - $r - 1, $r], [$r, $s - $r - 1], [$s - $r - 1, $s - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $bg);
        }
        imagealphablending($im, true);
    }
    $area = $s * $safe;                               // sisi kotak aman di tengah
    $off = ($s - $area) / 2;
    $mw = (int) round($area * 0.78);
    $mh = (int) round(imagesy($mark) * $mw / imagesx($mark));
    $gap = $area * 0.07;
    $fontSize = $area * 0.092;                          // tinggi huruf besar ≈ 0.7 x ukuran
    $blockH = $mh + $gap + $fontSize * 1.0;
    $top = (int) round($off + ($area - $blockH) / 2);
    place($im, $mark, intdiv($s, 2), $top, $mw);
    $white = imagecolorallocate($im, 255, 255, 255);
    spaced($im, $font, $fontSize, 'PEMINJAMAN', intdiv($s, 2), (int) round($top + $mh + $gap + $fontSize), $white, $fontSize * 0.16);
    return $im;
}

echo "Menulis berkas:\n";
$markOut = imagecreatetruecolor(512, (int) round($ch * 512 / $cw));
imagealphablending($markOut, false);
imagesavealpha($markOut, true);
imagefill($markOut, 0, 0, imagecolorallocatealpha($markOut, 0, 0, 0, 127));
imagecopyresampled($markOut, $mark, 0, 0, 0, 0, 512, imagesy($markOut), $cw, $ch);
save($markOut, $dir . '/logo-mark.png');

save(icon(192, $mark, $font, true, 0.9), $dir . '/icon-192.png');
save(icon(512, $mark, $font, true, 0.9), $dir . '/icon-512.png');
save(icon(512, $mark, $font, false, 0.8), $dir . '/icon-maskable-512.png');
save(icon(180, $mark, $font, false, 0.86), $dir . '/apple-touch-icon.png');

// favicon: heksagon saja pada kotak transparan
$fav = canvas(32, true);
place($fav, $mark, 16, (int) round((32 - 32 * $ch / $cw) / 2), 32);
save($fav, $dir . '/favicon-32.png');

// lencana notifikasi Android: siluet putih
$badge = canvas(96, true);
$mw = 84;
$mh = (int) round($ch * $mw / $cw);
$tmp = imagecreatetruecolor($mw, $mh);
imagealphablending($tmp, false);
imagesavealpha($tmp, true);
imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
imagecopyresampled($tmp, $mark, 0, 0, 0, 0, $mw, $mh, $cw, $ch);
for ($y = 0; $y < $mh; $y++) {
    for ($x = 0; $x < $mw; $x++) {
        $a = (imagecolorat($tmp, $x, $y) >> 24) & 0x7F;
        imagesetpixel($tmp, $x, $y, imagecolorallocatealpha($tmp, 255, 255, 255, $a));
    }
}
imagealphablending($badge, false);
imagecopy($badge, $tmp, intdiv(96 - $mw, 2), intdiv(96 - $mh, 2), 0, 0, $mw, $mh);
save($badge, $dir . '/badge-96.png');
echo "Selesai.\n";
