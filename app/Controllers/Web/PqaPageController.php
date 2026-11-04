<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;

final class PqaPageController extends BaseController
{
    /** Tracker PQA (staff & admin). Daftar dimuat klien dari API. */
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, 'pqa', 'pqa', 'Tracker PQA');
    }

    /** Menu tambahan admin (bilah bawah ponsel hanya memuat 5 tujuan utama). */
    public function more(Request $request, array $params): Response
    {
        return $this->page($request, 'more', 'more', 'Lainnya');
    }
}
