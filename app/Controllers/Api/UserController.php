<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

/** Semua route di controller ini berada di grup middleware auth + pwd + role:admin (lihat routes/api.php). */
final class UserController
{
    public function index(Request $request, array $params): Response
    {
        return Response::ok(UserService::listAll());
    }

    public function store(Request $request, array $params): Response
    {
        return Response::ok(UserService::create($request->body(), $request->user() ?? [], $request), 201);
    }

    public function update(Request $request, array $params): Response
    {
        return Response::ok(UserService::update((int) $params['id'], $request->body(), $request->user() ?? [], $request));
    }

    public function activate(Request $request, array $params): Response
    {
        return Response::ok(UserService::setActive((int) $params['id'], true, $request->user() ?? [], $request));
    }

    public function deactivate(Request $request, array $params): Response
    {
        return Response::ok(UserService::setActive((int) $params['id'], false, $request->user() ?? [], $request));
    }

    public function resetPassword(Request $request, array $params): Response
    {
        return Response::ok(UserService::resetPassword((int) $params['id'], $request->user() ?? [], $request));
    }
}
