<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

final class UserPageController extends BaseController
{
    public function index(Request $request, array $params): Response
    {
        return $this->page($request, 'users', 'users', 'Pengguna', ['users' => UserService::listAll()]);
    }
}
