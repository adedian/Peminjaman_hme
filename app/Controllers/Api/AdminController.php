<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\SettingsService;

/** Log aktivitas & pengaturan — khusus admin (dipasang di routes/api.php). */
final class AdminController
{
    public function logs(Request $request, array $params): Response
    {
        $f = [];
        foreach (['group', 'user_id', 'q', 'from', 'to'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $f[$k] = $v;
            }
        }
        $int = static fn($v, int $d): int => is_string($v) && ctype_digit($v) ? (int) $v : $d;
        return Response::ok(AuditService::list($f, $int($request->query('limit'), 50), $int($request->query('offset'), 0)));
    }

    public function settings(Request $request, array $params): Response
    {
        return Response::ok(SettingsService::show());
    }

    public function updateSettings(Request $request, array $params): Response
    {
        return Response::ok(SettingsService::update($request->body(), $request->user() ?? [], $request));
    }

    public function testEmail(Request $request, array $params): Response
    {
        return Response::ok(SettingsService::testEmail($request->user() ?? [], $request));
    }
}
