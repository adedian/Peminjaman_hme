<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use Throwable;

/**
 * Penyimpanan foto barang yang aman.
 *
 * Prinsip: berkas unggahan TIDAK PERNAH disimpan apa adanya. Isinya divalidasi (bukan nama/ekstensi/Content-Type
 * kiriman klien), didekode menjadi bitmap, lalu dienkode ulang oleh GD. Dengan begitu metadata (EXIF/GPS),
 * komentar, dan muatan tersembunyi (poliglot GIF+PHP, PNG dengan chunk aneh, dll.) hilang.
 *  - Hanya JPEG/PNG/WebP (SVG sengaja ditolak: dapat memuat skrip).
 *  - Batas ukuran berkas, batas piksel (anti decompression-bomb) dan estimasi memori sebelum dekode.
 *  - Nama berkas acak 128-bit; disimpan di storage/uploads/items (di luar web root), disajikan lewat controller.
 *  - Dua varian: utama (maks 1200 px) dan thumbnail (maks 360 px).
 */
final class ImageService
{
    public const MAIN_SIDE = 1200;
    public const THUMB_SIDE = 360;
    public const MAX_PIXELS = 24000000;   // 24 megapiksel
    public const NAME_PATTERN = '/^[a-f0-9]{32}(_t)?\.(jpg|png|webp)$/';

    /** @var array<string,string> mime → ekstensi */
    private const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    /** @var array<string,int> mime → konstanta IMAGETYPE */
    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    private static bool $testing = false;

    /**
     * Hanya untuk tes: terima berkas biasa (di CLI is_uploaded_file() selalu false).
     * Tidak berefek kecuali APP_ENV=testing, sehingga tidak dapat dipakai untuk melonggarkan production.
     */
    public static function allowPlainFilesForTests(bool $on): void
    {
        self::$testing = $on && Config::get('app.env') === 'testing';
    }

    public static function dir(): string
    {
        return rtrim((string) Config::get('paths.uploads'), '/\\') . '/items';
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name);
    }

    public static function path(string $name): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException('Nama berkas tidak valid.');
        }
        return self::dir() . '/' . $name;
    }

    /** Nama thumbnail dari nama utama: abc.jpg → abc_t.jpg */
    public static function thumbName(string $name): string
    {
        return preg_replace('/\.(jpg|png|webp)$/', '_t.$1', $name) ?? $name;
    }

    /** Hapus berkas utama + thumbnail; tidak melempar bila tidak ada. */
    public static function delete(?string $name): void
    {
        if ($name === null || !self::isValidName($name) || substr($name, -6, 2) === '_t') {
            return;
        }
        foreach ([$name, self::thumbName($name)] as $n) {
            $p = self::dir() . '/' . $n;
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /**
     * Validasi + olah ulang + simpan. Mengembalikan nama berkas utama.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @throws HttpException 422 dengan fields['photo'] bila ditolak
     */
    public static function store(array $file): string
    {
        $max = (int) Config::get('upload.max_bytes', 3 * 1024 * 1024);
        self::assertUploadOk($file, $max);
        return self::process((string) $file['tmp_name']);
    }

    /**
     * Simpan foto dari berkas lokal — HANYA untuk alat CLI tepercaya (migrasi data lama). Melalui validasi & olah ulang
     * yang sama dengan unggahan; batas ukuran 15 MB karena foto Drive asli bisa besar (hasil akhirnya tetap diperkecil).
     *
     * @throws HttpException 422 bila berkas bukan gambar valid
     */
    public static function storeLocal(string $path): string
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('storeLocal hanya untuk CLI.');
        }
        $size = (int) @filesize($path);
        if (!is_file($path) || $size <= 0) {
            self::reject('File foto kosong atau tidak ditemukan.');
        }
        if ($size > 15 * 1024 * 1024) {
            self::reject('Ukuran foto terlalu besar (maksimal 15 MB).');
        }
        return self::process($path);
    }

    private static function process(string $tmp): string
    {
        $bytes = @file_get_contents($tmp);
        if ($bytes === false || $bytes === '') {
            // Mis. antivirus/OS memblokir berkas, atau berkas hilang: tolak dengan sopan, bukan error 500
            self::reject('Foto gagal dibaca. Coba lagi dengan berkas lain.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::ALLOWED[$mime])) {
            self::reject('Format foto harus JPG, PNG, atau WebP.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info[2] ?? 0) !== self::TYPES[$mime] || $info[0] < 1 || $info[1] < 1) {
            self::reject('File bukan gambar yang valid.');
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w * $h > self::MAX_PIXELS) {
            self::reject('Resolusi foto terlalu besar (maksimal 24 megapiksel). Perkecil fotonya lalu coba lagi.');
        }
        self::assertMemory($w, $h);

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            self::reject('File gambar rusak atau tidak dapat dibaca.');
        }
        unset($bytes);

        try {
            if ($mime === 'image/jpeg') {
                $img = self::applyOrientation($img, $tmp);
            }
            $ext = self::ALLOWED[$mime];
            $base = bin2hex(random_bytes(16));
            $main = $base . '.' . $ext;
            $thumb = $base . '_t.' . $ext;

            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Folder unggahan tidak dapat dibuat.');
            }

            $ok = self::write(self::fit($img, self::MAIN_SIDE), $mime, $dir . '/' . $main)
               && self::write(self::fit($img, self::THUMB_SIDE), $mime, $dir . '/' . $thumb);
            if (!$ok) {
                @unlink($dir . '/' . $main);
                @unlink($dir . '/' . $thumb);
                throw new \RuntimeException('Foto gagal disimpan.');
            }
            return $main;
        } finally {
            imagedestroy($img);
        }
    }

    /** @param array<string,mixed> $file */
    private static function assertUploadOk(array $file, int $max): void
    {
        $mb = rtrim(rtrim(number_format($max / 1048576, 1, ',', ''), '0'), ',');
        switch ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                self::reject("Ukuran foto terlalu besar (maksimal {$mb} MB).");
                // no break
            case UPLOAD_ERR_PARTIAL:
                self::reject('Unggahan terputus. Coba lagi.');
                // no break
            default:
                self::reject('Foto gagal diunggah. Coba lagi.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        // Hanya berkas yang benar-benar dibuat PHP dari unggahan HTTP (menolak path buatan penyerang)
        if ($tmp === '' || (!is_uploaded_file($tmp) && !self::$testing)) {
            self::reject('Foto gagal diunggah. Coba lagi.');
        }
        $size = (int) @filesize($tmp);
        if ($size <= 0) {
            self::reject('File foto kosong.');
        }
        if ($size > $max) {
            self::reject("Ukuran foto terlalu besar (maksimal {$mb} MB).");
        }
    }

    /** Dekode GD memakai ≈ lebar×tinggi×4 byte (+ salinan saat resize). Tolak bila memori tidak cukup. */
    private static function assertMemory(int $w, int $h): void
    {
        $limit = self::memoryLimitBytes();
        if ($limit < 0) {
            return; // tanpa batas
        }
        $need = (int) ($w * $h * 4 * 2.5);
        if ($need + memory_get_usage(true) > $limit) {
            self::reject('Resolusi foto terlalu besar untuk diproses server. Perkecil fotonya lalu coba lagi.');
        }
    }

    private static function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1073741824;
            case 'm': return $n * 1048576;
            case 'k': return $n * 1024;
        }
        return $n;
    }

    /** @param \GdImage|resource $img @return \GdImage|resource */
    private static function applyOrientation($img, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle === 0) {
            return $img;
        }
        $rot = imagerotate($img, $angle, 0);
        if ($rot === false) {
            return $img;
        }
        imagedestroy($img);
        return $rot;
    }

    /** Perkecil agar sisi terpanjang ≤ $side (tidak pernah memperbesar). @param \GdImage|resource $img @return \GdImage|resource */
    private static function fit($img, int $side)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= $side) {
            return $img;
        }
        $scale = $side / $long;
        $out = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
        return $out === false ? $img : $out;
    }

    /** @param \GdImage|resource $img */
    private static function write($img, string $mime, string $dest): bool
    {
        try {
            switch ($mime) {
                case 'image/jpeg':
                    // Latar putih bila sumber punya alpha (hasil dekode string tidak seharusnya, tetapi aman)
                    imageinterlace($img, true);
                    return imagejpeg($img, $dest, 82);
                case 'image/png':
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    return imagepng($img, $dest, 6);
                case 'image/webp':
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    return imagewebp($img, $dest, 82);
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    private static function reject(string $message): void
    {
        throw new HttpException(422, 'validation_failed', 'Foto tidak dapat diterima.', ['photo' => $message]);
    }
}
