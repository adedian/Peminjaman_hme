<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\DashboardService;

/** Dashboard beranda. Login diperlukan; isi disaring per peran di DashboardService. */
final class DashboardController
{
    public function show(Request $request, array $params): Response
    {
        return Response::ok(DashboardService::data($request->user() ?? []));
    }
}
