<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ImageService;

/**
 * Menyajikan foto barang dari storage/ (di luar web root). Hanya untuk pengguna yang sudah login.
 * Nama berkas divalidasi ketat (32 hex + ekstensi putih) sehingga tidak ada path traversal.
 */
final class MediaController
{
    private const TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function item(Request $request, array $params): Response
    {
        $name = (string) ($params['file'] ?? '');
        if (!ImageService::isValidName($name)) {
            throw new HttpException(404, 'not_found', 'Foto tidak ditemukan.');
        }
        $path = ImageService::path($name);
        if (!is_file($path)) {
            throw new HttpException(404, 'not_found', 'Foto tidak ditemukan.');
        }

        $etag = '"' . md5($name . filemtime($path) . filesize($path)) . '"';
        $headers = [
            'Content-Type'           => self::TYPES[pathinfo($name, PATHINFO_EXTENSION)],
            'Cache-Control'          => 'private, max-age=86400',   // private: jangan di-cache oleh proxy bersama
            'ETag'                   => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ];
        if ($request->header('if-none-match') === $etag) {
            return new Response('', 304, $headers);
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            // Berkas terkunci sesaat (antivirus/disk) → jangan kirim 200 dengan badan kosong yang akan di-cache browser
            throw new HttpException(503, 'media_unavailable', 'Foto sementara tidak dapat dibaca. Coba lagi.', [], ['Retry-After' => '2']);
        }
        return new Response($bytes, 200, $headers);
    }
}
