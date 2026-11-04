<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;

/** Rekap & laporan Excel — khusus admin (dipasang di routes/api.php). */
final class ReportController
{
    public function summary(Request $request, array $params): Response
    {
        $period = ReportService::period($request->query('month'), $request->query('year'));
        return Response::ok(ReportService::summary($period));
    }

    public function export(Request $request, array $params): Response
    {
        $period = ReportService::period($request->query('month'), $request->query('year'));
        $file = ReportService::exportXlsx($period, $request->user() ?? [], $request);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $file['filename']) ?? 'Laporan_Stok_HME.xlsx';
        return new Response($file['bytes'], 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length'      => (string) strlen($file['bytes']),
            'Cache-Control'       => 'no-store',
        ]);
    }
}
