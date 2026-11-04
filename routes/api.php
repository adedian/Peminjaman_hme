<?php
declare(strict_types=1);

use App\Controllers\Api\AdminController;
use App\Controllers\Api\ArchiveController;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\NotificationController;
use App\Controllers\Api\HealthController;
use App\Controllers\Api\ItemController;
use App\Controllers\Api\LoanController;
use App\Controllers\Api\PqaController;
use App\Controllers\Api\ReportController;
use App\Controllers\Api\SyncController;
use App\Controllers\Api\RequestController;
use App\Controllers\Api\UserController;
use App\Core\Config;
use App\Core\Router;

/** @var Router $router */

/*
 * ATURAN: setiap endpoint di sini mengautentikasi & mengotorisasi SENDIRI lewat middleware grupnya.
 * Menyembunyikan tombol di frontend BUKAN kontrol akses.
 *   auth  = harus login            pwd  = sudah mengganti password sementara
 *   role  = peran yang diizinkan   csrf = otomatis untuk POST/PUT/PATCH/DELETE (lihat Router)
 */
$router->group(['prefix' => '/api/v1'], static function (Router $r): void {
    // ---- Publik -------------------------------------------------------------------------------
    $r->get('/health', [HealthController::class, 'show']);
    $r->post('/auth/login', [AuthController::class, 'login']);

    // ---- Login diperlukan (boleh sebelum mengganti password sementara) -----------------------
    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->get('/me', [AuthController::class, 'me']);
        $r->post('/auth/logout', [AuthController::class, 'logout']);
        $r->post('/me/password', [AuthController::class, 'changePassword']);
    });

    // ---- Login + password sudah diganti -------------------------------------------------------
    $r->group(['middleware' => ['auth', 'pwd']], static function (Router $r): void {
        // Khusus admin
        $r->group(['prefix' => '/users', 'middleware' => ['role:admin']], static function (Router $r): void {
            $r->get('', [UserController::class, 'index']);
            $r->post('', [UserController::class, 'store']);
            $r->put('/{id:\d+}', [UserController::class, 'update']);
            $r->post('/{id:\d+}/activate', [UserController::class, 'activate']);
            $r->post('/{id:\d+}/deactivate', [UserController::class, 'deactivate']);
            $r->post('/{id:\d+}/reset-password', [UserController::class, 'resetPassword']);
        });

        // Peminjaman — dapat dibaca kedua peran (staff hanya melihat miliknya; dipaksa di LoanService)
        $r->get('/items/available', [RequestController::class, 'availableItems']);
        $r->get('/loans/summary', [RequestController::class, 'summary']);
        $r->get('/requests', [RequestController::class, 'index']);
        $r->get('/requests/{id:\d+}', [RequestController::class, 'show']);

        // Tracker PQA — dibaca semua pengguna login (seperti sistem lama)
        $r->get('/pqa/units', [PqaController::class, 'units']);
        $r->get('/pqa', [PqaController::class, 'index']);
        $r->get('/pqa/{id:\d+}', [PqaController::class, 'show']);
        $r->group(['middleware' => ['role:staff']], static function (Router $r): void {
            $r->post('/pqa', [PqaController::class, 'store']);
            $r->post('/pqa/{id:\d+}/extend', [PqaController::class, 'extend']);
            $r->post('/pqa/{id:\d+}/remove', [PqaController::class, 'remove']);
        });
        $r->group(['middleware' => ['role:admin']], static function (Router $r): void {
            $r->post('/pqa/{id:\d+}/approve', [PqaController::class, 'approve']);
            $r->post('/pqa/{id:\d+}/reject', [PqaController::class, 'reject']);
            $r->post('/pqa/{id:\d+}/extension/approve', [PqaController::class, 'approveExtension']);
            $r->post('/pqa/{id:\d+}/extension/reject', [PqaController::class, 'rejectExtension']);
            $r->post('/pqa/{id:\d+}/removal/approve', [PqaController::class, 'approveRemoval']);
            $r->post('/pqa/{id:\d+}/removal/reject', [PqaController::class, 'rejectRemoval']);
        });

        // Staff (pemilik)
        $r->group(['middleware' => ['role:staff']], static function (Router $r): void {
            $r->post('/requests', [RequestController::class, 'store']);
            $r->post('/requests/{id:\d+}/cancel', [RequestController::class, 'cancel']);
            $r->post('/requests/{id:\d+}/return', [RequestController::class, 'returnItems']);
            $r->post('/loans/{id:\d+}/cancel', [LoanController::class, 'cancel']);
            $r->post('/loans/{id:\d+}/return', [LoanController::class, 'returnItem']);
            $r->post('/loans/{id:\d+}/swap', [LoanController::class, 'swap']);
        });

        // Admin
        $r->group(['middleware' => ['role:admin']], static function (Router $r): void {
            $r->post('/requests/{id:\d+}/approve', [RequestController::class, 'approve']);
            $r->post('/requests/{id:\d+}/reject', [RequestController::class, 'reject']);
            $r->post('/loans/{id:\d+}/approve', [LoanController::class, 'approve']);
            $r->post('/loans/{id:\d+}/reject', [LoanController::class, 'reject']);
        });

        // Realtime: polling perubahan (tidak memperpanjang sesi idle — lihat AuthMiddleware)
        $r->get('/sync', [SyncController::class, 'sync']);

        // Notifikasi in-app — milik sendiri (semua peran)
        $r->get('/notifications', [NotificationController::class, 'index']);
        $r->get('/notifications/count', [NotificationController::class, 'count']);
        $r->post('/notifications/read-all', [NotificationController::class, 'readAll']);
        $r->post('/notifications/{id:\d+}/read', [NotificationController::class, 'read']);

        // Arsip data lama & laporan migrasi (admin, hanya baca)
        $r->group(['middleware' => ['role:admin']], static function (Router $r): void {
            $r->get('/archive/manual', [ArchiveController::class, 'manual']);
            $r->get('/archive/register', [ArchiveController::class, 'register']);
            $r->get('/migration/runs', [ArchiveController::class, 'runs']);
            $r->get('/migration/runs/{id:\d+}/issues', [ArchiveController::class, 'issues']);
            $r->get('/migration/runs/{id:\d+}/export', [ArchiveController::class, 'export']);
        });

        // Log aktivitas & pengaturan (admin)
        $r->group(['middleware' => ['role:admin']], static function (Router $r): void {
            $r->get('/logs', [AdminController::class, 'logs']);
            $r->get('/settings', [AdminController::class, 'settings']);
            $r->put('/settings', [AdminController::class, 'updateSettings']);
            $r->post('/settings/test-email', [AdminController::class, 'testEmail']);
        });

        // Rekap & laporan Excel (admin)
        $r->group(['prefix' => '/reports', 'middleware' => ['role:admin']], static function (Router $r): void {
            $r->get('/summary', [ReportController::class, 'summary']);
            $r->get('/export', [ReportController::class, 'export']);
        });

        $r->group(['middleware' => ['role:admin']], static function (Router $r): void {
            $r->get('/categories', [ItemController::class, 'categories']);
            $r->group(['prefix' => '/items'], static function (Router $r): void {
                $r->get('', [ItemController::class, 'index']);
                $r->post('', [ItemController::class, 'store']);
                $r->get('/{id:\d+}', [ItemController::class, 'show']);
                $r->post('/{id:\d+}', [ItemController::class, 'update']);
                $r->post('/{id:\d+}/activate', [ItemController::class, 'activate']);
                $r->post('/{id:\d+}/deactivate', [ItemController::class, 'deactivate']);
                $r->post('/{id:\d+}/delete', [ItemController::class, 'destroy']);
            });
        });
    });

    // Probe CSRF untuk tes — tidak ada di production
    if (!Config::isProduction()) {
        $r->post('/_csrf-probe', [HealthController::class, 'csrfProbe']);
    }
});
