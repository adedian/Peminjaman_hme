<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AdminPageController extends BaseController
{
    /** Notifikasi milik pengguna (semua peran). Daftar dimuat klien dari API. */
    public function notifications(Request $request, array $params): Response
    {
        return $this->page($request, 'notifications', 'notifications', 'Notifikasi');
    }

    /** Log aktivitas (admin). */
    public function logs(Request $request, array $params): Response
    {
        $users = Database::all('SELECT id, name, username FROM users ORDER BY name');
        return $this->page($request, 'logs', 'logs', 'Log Aktivitas', ['users' => $users]);
    }

    /** Arsip catatan manual & register inventaris (admin, Q1/Q2). */
    public function archive(Request $request, array $params): Response
    {
        $tab = $request->query('tab') === 'register' ? 'register' : 'manual';
        return $this->page($request, 'archive', 'archive', 'Arsip Data Lama', ['tab' => $tab]);
    }

    /** Laporan migrasi per baris (admin, §8). */
    public function migration(Request $request, array $params): Response
    {
        return $this->page($request, 'migration', 'migration', 'Laporan Migrasi');
    }

    /** Pengaturan email notifikasi (admin). */
    public function settings(Request $request, array $params): Response
    {
        return $this->page($request, 'settings', 'settings', 'Pengaturan');
    }
}
