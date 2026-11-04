<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PqaService;

/**
 * Tracker PQA. Pembatasan peran dipasang di routes/api.php dan DIULANG di PqaService.
 * Seperti sistem lama, semua pengguna login dapat MEMBACA seluruh unit PQA (tracker operasional bersama).
 */
final class PqaController
{
    public function units(Request $request, array $params): Response
    {
        return Response::ok(PqaService::availableUnits());
    }

    public function index(Request $request, array $params): Response
    {
        $filters = [];
        foreach (['group', 'q'] as $k) {
            $v = $request->query($k);
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_string($v)) {
                throw new HttpException(422, 'validation_failed', 'Filter tidak valid.', [$k => 'Nilai filter tidak valid.']);
            }
            $filters[$k] = $v;
        }
        $int = static function ($v, int $default): int {
            return is_string($v) && ctype_digit($v) ? (int) $v : $default;
        };
        return Response::ok(PqaService::list($request->user() ?? [], $filters, $int($request->query('limit'), 30), $int($request->query('offset'), 0)));
    }

    public function show(Request $request, array $params): Response
    {
        $r = PqaService::find((int) $params['id'], $request->user() ?? [], true);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Data PQA tidak ditemukan.');
        }
        return Response::ok($r);
    }

    public function store(Request $request, array $params): Response
    {
        return Response::ok(PqaService::submit($request->user() ?? [], $request->body(), $request), 201);
    }

    public function extend(Request $request, array $params): Response
    {
        return Response::ok(PqaService::requestExtension((int) $params['id'], $request->input('days'), $request->user() ?? [], $request));
    }

    public function remove(Request $request, array $params): Response
    {
        return Response::ok(PqaService::requestRemoval((int) $params['id'], $request->input('team'), $request->user() ?? [], $request));
    }

    public function approve(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideInstall((int) $params['id'], true, $request->user() ?? [], $request));
    }

    public function reject(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideInstall((int) $params['id'], false, $request->user() ?? [], $request));
    }

    public function approveExtension(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideExtension((int) $params['id'], true, $request->user() ?? [], $request));
    }

    public function rejectExtension(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideExtension((int) $params['id'], false, $request->user() ?? [], $request));
    }

    public function approveRemoval(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideRemoval((int) $params['id'], true, $request->user() ?? [], $request));
    }

    public function rejectRemoval(Request $request, array $params): Response
    {
        return Response::ok(PqaService::decideRemoval((int) $params['id'], false, $request->user() ?? [], $request));
    }
}
