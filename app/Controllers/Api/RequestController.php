<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\LoanService;

/**
 * Pengajuan peminjaman (loan_batches). Pembatasan peran dipasang di routes/api.php DAN diulang di LoanService.
 * Staff hanya dapat membaca/mengubah miliknya; objek milik orang lain dijawab 404 (bukan 403).
 */
final class RequestController
{
    public function availableItems(Request $request, array $params): Response
    {
        return Response::ok(LoanService::availableItems());
    }

    public function summary(Request $request, array $params): Response
    {
        return Response::ok(LoanService::summary($request->user() ?? []));
    }

    public function index(Request $request, array $params): Response
    {
        $filters = [];
        foreach (['status', 'q', 'user_id'] as $k) {
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
        return Response::ok(LoanService::listRequests(
            $request->user() ?? [], $filters, $int($request->query('limit'), 30), $int($request->query('offset'), 0)
        ));
    }

    public function show(Request $request, array $params): Response
    {
        $r = LoanService::findRequest((int) $params['id'], $request->user() ?? []);
        if ($r === null) {
            throw new HttpException(404, 'not_found', 'Pengajuan tidak ditemukan.');
        }
        return Response::ok($r);
    }

    public function store(Request $request, array $params): Response
    {
        return Response::ok(LoanService::submit($request->user() ?? [], $request->body(), $request), 201);
    }

    public function cancel(Request $request, array $params): Response
    {
        return Response::ok(LoanService::cancelRequest((int) $params['id'], $request->user() ?? [], $request));
    }

    public function returnItems(Request $request, array $params): Response
    {
        return Response::ok(LoanService::returnRequest((int) $params['id'], $request->user() ?? [], $request));
    }

    public function approve(Request $request, array $params): Response
    {
        return Response::ok(LoanService::approveRequest((int) $params['id'], $request->user() ?? [], $request));
    }

    public function reject(Request $request, array $params): Response
    {
        return Response::ok(LoanService::rejectRequest((int) $params['id'], $request->user() ?? [], $request));
    }
}
