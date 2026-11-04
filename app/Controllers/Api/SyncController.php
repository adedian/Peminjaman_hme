<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\SyncService;

/** Polling realtime. Sesi tidak diperpanjang (lihat AuthMiddleware) dan langsung dilepas agar tidak mengunci request lain. */
final class SyncController
{
    public function sync(Request $request, array $params): Response
    {
        Session::release();
        $since = $request->query('since', '0');
        if (!is_string($since) || !ctype_digit($since) || strlen($since) > 19) {
            throw new HttpException(422, 'validation_failed', 'Parameter tidak valid.', ['since' => 'Harus berupa angka.']);
        }
        $user = $request->user() ?? [];
        if ($since === '0') {
            return Response::ok(['cursor' => SyncService::cursor(), 'reset' => false, 'changes' => (object) [], 'counts' => SyncService::counts($user), 'notifications' => []]);
        }
        $d = SyncService::poll($user, (int) $since);
        return $d === null ? Response::noContent() : Response::ok($d);
    }
}
