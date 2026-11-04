<?php
declare(strict_types=1);

use App\Controllers\Web\AdminPageController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\ItemPageController;
use App\Controllers\Web\MediaController;
use App\Controllers\Web\PqaPageController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\ReportPageController;
use App\Controllers\Web\RequestPageController;
use App\Controllers\Web\UserPageController;
use App\Core\Router;

/** @var Router $router */

// Publik
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);

// Login diperlukan (boleh sebelum mengganti password sementara)
$router->group(['middleware' => ['auth']], static function (Router $r): void {
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/profile', [ProfileController::class, 'show']);
    $r->get('/profile/password', [ProfileController::class, 'show']);
    $r->post('/profile/password', [ProfileController::class, 'changePassword']);
});

// Login + password sudah diganti
$router->group(['middleware' => ['auth', 'pwd']], static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/requests', [RequestPageController::class, 'index']);
    $r->get('/requests/new', [RequestPageController::class, 'create'], ['role:staff']);
    $r->get('/pqa', [PqaPageController::class, 'index']);
    $r->get('/more', [PqaPageController::class, 'more'], ['role:admin']);
    $r->get('/users', [UserPageController::class, 'index'], ['role:admin']);
    $r->get('/items', [ItemPageController::class, 'index'], ['role:admin']);
    $r->get('/recap', [ReportPageController::class, 'index'], ['role:admin']);
    $r->get('/notifications', [AdminPageController::class, 'notifications']);
    $r->get('/logs', [AdminPageController::class, 'logs'], ['role:admin']);
    $r->get('/archive', [AdminPageController::class, 'archive'], ['role:admin']);
    $r->get('/migration', [AdminPageController::class, 'migration'], ['role:admin']);
    $r->get('/settings', [AdminPageController::class, 'settings'], ['role:admin']);
    $r->get('/media/items/{file}', [MediaController::class, 'item']);
});
