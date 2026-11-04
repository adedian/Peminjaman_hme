<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ArchiveService;
use App\Services\AuditService;

/** Arsip data lama & laporan migrasi — khusus admin, hanya baca (dipasang di routes/api.php). */
final class ArchiveController
{
    /** @return array{0:array<string,mixed>,1:int,2:int} */
    private static function page(Request $request): array
    {
        $int = static fn($v, int $d): int => is_string($v) && ctype_digit($v) ? (int) $v : $d;
        $f = [];
        foreach (['q', 'filter'] as $k) {
            $v = $request->query($k);
            if ($v !== null && $v !== '') {
                $f[$k] = $v;
            }
        }
        return [$f, $int($request->query('limit'), 50), $int($request->query('offset'), 0)];
    }

    public function manual(Request $request, array $params): Response
    {
        [$f, $l, $o] = self::page($request);
        return Response::ok(ArchiveService::manual($f, $l, $o));
    }

    public function register(Request $request, array $params): Response
    {
        [$f, $l, $o] = self::page($request);
        return Response::ok(ArchiveService::register($f, $l, $o));
    }

    public function runs(Request $request, array $params): Response
    {
        return Response::ok(ArchiveService::runs());
    }

    public function issues(Request $request, array $params): Response
    {
        [$f, $l, $o] = self::page($request);
        return Response::ok(ArchiveService::issues((int) $params['id'], $f, $l, $o));
    }

    public function export(Request $request, array $params): Response
    {
        $file = ArchiveService::issuesXlsx((int) $params['id']);
        AuditService::log('migration.report_exported', (int) ($request->user()['id'] ?? 0), 'migration', (int) $params['id'], null, null, $request);
        return new Response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'Content-Length' => (string) strlen($file['bytes']), 'Cache-Control' => 'no-store',
        ]);
    }
}
