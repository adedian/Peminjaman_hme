<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\PushService;

/**
 * Notifikasi push (PWA).
 *  - semua peran (login): konfigurasi publik, daftar/hapus perangkat SENDIRI, notifikasi uji ke perangkat sendiri
 *  - admin saja: status, log pengiriman, kirim ulang
 * Tidak ada endpoint yang menerima id pengguna lain: pengguna hanya dapat mengelola langganannya sendiri.
 */
final class PushController
{
    public function config(Request $request, array $params): Response
    {
        return Response::ok(PushService::config());
    }

    public function subscribe(Request $request, array $params): Response
    {
        return Response::ok(PushService::subscribe($request->user() ?? [], $request->body(), $request->header('user-agent'), $request), 201);
    }

    public function unsubscribe(Request $request, array $params): Response
    {
        return Response::ok(PushService::unsubscribe($request->user() ?? [], $request->input('endpoint'), $request));
    }

    public function devices(Request $request, array $params): Response
    {
        return Response::ok(PushService::devices($request->user() ?? []));
    }

    public function removeDevice(Request $request, array $params): Response
    {
        return Response::ok(PushService::removeDevice($request->user() ?? [], (int) $params['id'], $request));
    }

    public function test(Request $request, array $params): Response
    {
        return Response::ok(PushService::sendTest($request->user() ?? [], $request));
    }

    public function status(Request $request, array $params): Response
    {
        return Response::ok(PushService::status());
    }

    public function log(Request $request, array $params): Response
    {
        $int = static fn($v, int $d): int => is_string($v) && ctype_digit($v) ? (int) $v : $d;
        $f = [];
        $st = $request->query('status');
        if (is_string($st) && $st !== '') {
            $f['status'] = $st;
        }
        return Response::ok(PushService::log($f, $int($request->query('limit'), 30), $int($request->query('offset'), 0)));
    }

    public function retry(Request $request, array $params): Response
    {
        $r = PushService::retry((int) $params['id'], $request->user() ?? [], $request);
        PushService::drain(3, 10);
        return Response::ok($r);
    }
}
