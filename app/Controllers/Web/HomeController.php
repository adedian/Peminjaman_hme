<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\LoanService;

final class HomeController extends BaseController
{
    /** Beranda: ringkasan angka dirender server; daftar pengajuan diisi klien dari API (satu implementasi tampilan). */
    public function index(Request $request, array $params): Response
    {
        $user = $request->user() ?? [];
        return $this->page($request, 'home', 'home', 'Beranda', ['summary' => LoanService::summary($user)]);
    }
}
