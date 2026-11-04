<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;

final class ReportPageController extends BaseController
{
    /** Rekap bulanan/tahunan + unduh laporan Excel (admin). Angka dimuat klien dari API. */
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, 'recap', 'recap', 'Rekap', [
            'month' => (int) date('n'),
            'year'  => (int) date('Y'),
        ]);
    }
}
