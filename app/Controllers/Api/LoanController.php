<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\LoanService;

/** Aksi pada satu barang dalam pengajuan. Mengembalikan pengajuan terbaru. */
final class LoanController
{
    public function approve(Request $request, array $params): Response
    {
        return Response::ok(LoanService::approveLoan((int) $params['id'], $request->user() ?? [], $request));
    }

    public function reject(Request $request, array $params): Response
    {
        return Response::ok(LoanService::rejectLoan((int) $params['id'], $request->user() ?? [], $request));
    }

    public function cancel(Request $request, array $params): Response
    {
        return Response::ok(LoanService::cancelLoan((int) $params['id'], $request->user() ?? [], $request));
    }

    public function returnItem(Request $request, array $params): Response
    {
        return Response::ok(LoanService::returnLoan((int) $params['id'], $request->user() ?? [], $request));
    }

    public function swap(Request $request, array $params): Response
    {
        return Response::ok(LoanService::swapItem((int) $params['id'], $request->input('item_id'), $request->user() ?? [], $request));
    }
}
