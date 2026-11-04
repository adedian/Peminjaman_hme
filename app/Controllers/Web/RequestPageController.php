<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class RequestPageController extends BaseController
{
    /** Daftar pengajuan: admin = "Permohonan", staff = "Riwayat". Data dimuat klien dari API. */
    public function index(Request $request, array $params): Response
    {
        $isAdmin = ($request->user()['role'] ?? '') === 'admin';
        return $this->page($request, 'requests', 'requests', $isAdmin ? 'Permohonan' : 'Riwayat', [
            'initial' => is_string($request->query('status')) ? $request->query('status') : '',
        ]);
    }

    /** Form pinjam baru (staff). Bila masih punya barang aktif (BR-02), formulir diganti penjelasan. */
    public function create(Request $request, array $params): Response
    {
        $uid = (int) ($request->user()['id'] ?? 0);
        $active = (int) Database::value("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'approved'", [$uid]);
        return $this->page($request, 'request_new', 'new', 'Pinjam Barang', ['hasActive' => $active > 0, 'today' => date('Y-m-d')]);
    }
}
