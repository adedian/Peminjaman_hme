<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

final class HealthController
{
    /** Publik & ringan: dipakai pemantau uptime dan skrip instalasi. Tidak membuka sesi. */
    public function show(Request $request, array $params): Response
    {
        $db = 'up';
        try {
            Database::value('SELECT 1');
        } catch (Throwable $e) {
            $db = 'down';
        }
        $data = ['status' => $db === 'up' ? 'ok' : 'degraded', 'db' => $db, 'time' => date('c')];
        if (Config::isDebug()) {
            $data['env'] = Config::get('app.env');
            $data['php'] = PHP_VERSION;
        }
        return Response::ok($data, $db === 'up' ? 200 : 503);
    }

    /** Hanya terdaftar di non-production — dipakai tes keamanan (CSRF). */
    public function csrfProbe(Request $request, array $params): Response
    {
        return Response::ok(['csrf' => 'accepted']);
    }
}
