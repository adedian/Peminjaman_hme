<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Berkas PWA yang dilayani PHP (bukan berkas statis) supaya mengikuti folder dasar aplikasi (APP_BASE_PATH),
 * tidak ikut cache 1 tahun milik aset statis, dan versi service worker otomatis berubah saat berkas berubah.
 *   GET /manifest.webmanifest   manifes aplikasi (nama, ikon, warna, pintasan)
 *   GET /sw.js                  service worker (cakupan = folder dasar aplikasi)
 *   GET /offline                halaman saat tidak ada koneksi (di-cache service worker)
 * Ketiganya publik dan tidak berisi data pengguna.
 */
final class PwaController
{
    public function manifest(Request $request, array $params): Response
    {
        $base = (string) config('app.base_path', '');
        $icon = static fn(string $file): string => url('/assets/img/' . $file);
        $manifest = [
            'id'               => ($base === '' ? '' : $base) . '/',
            'name'             => 'Peminjaman HME',
            'short_name'       => 'Peminjaman HME',
            'description'      => 'Peminjaman barang & tracker PQA PT Hexa Multi Energi.',
            'lang'             => 'id',
            'dir'              => 'ltr',
            'start_url'        => url('/') . '?source=pwa',
            'scope'            => url('/'),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#141414',
            'theme_color'      => '#1A1A1A',
            'categories'       => ['business', 'productivity'],
            'icons'            => [
                ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts'        => [
                ['name' => 'Pinjam Barang', 'short_name' => 'Pinjam', 'url' => url('/requests/new'), 'icons' => [['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']]],
                ['name' => 'Tracker PQA', 'short_name' => 'PQA', 'url' => url('/pqa'), 'icons' => [['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']]],
                ['name' => 'Notifikasi', 'short_name' => 'Notifikasi', 'url' => url('/notifications'), 'icons' => [['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']]],
            ],
        ];
        return new Response(
            (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'no-cache']
        );
    }

    public function serviceWorker(Request $request, array $params): Response
    {
        $file = HME_ROOT . '/app/Views/pwa/sw.js';
        $src = (string) file_get_contents($file);
        $precache = [
            url('/offline'),
            asset('css/tokens.css'), asset('css/components.css'), asset('css/app.css'),
            asset('img/icon-192.png'), asset('img/icon-512.png'),
        ];
        $version = substr(sha1($src . implode('|', $precache) . (string) @filemtime($file)), 0, 12);
        $body = strtr($src, [
            '__VERSION__'  => $version,
            '__PRECACHE__' => (string) json_encode($precache, JSON_UNESCAPED_SLASHES),
            '__OFFLINE__'  => url('/offline'),
            '__ICON__'     => asset('img/icon-192.png'),
            '__BADGE__'    => asset('img/badge-96.png'),
            '__HOME__'     => url('/'),
        ]);
        return new Response($body, 200, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Cache-Control'          => 'no-cache, max-age=0',
            'Service-Worker-Allowed' => url('/'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function offline(Request $request, array $params): Response
    {
        return View::response('pwa/offline', ['title' => 'Tidak ada koneksi'], 200, 'layout');
    }
}
