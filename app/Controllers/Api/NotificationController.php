<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

/** Notifikasi in-app milik pengguna yang sedang login (semua peran). */
final class NotificationController
{
    public function index(Request $request, array $params): Response
    {
        $int = static fn($v, int $d): int => is_string($v) && ctype_digit($v) ? (int) $v : $d;
        return Response::ok(NotificationService::list(
            (int) $request->user()['id'],
            $request->query('unread') === '1',
            $int($request->query('limit'), 20),
            $int($request->query('offset'), 0)
        ));
    }

    public function count(Request $request, array $params): Response
    {
        return Response::ok(['unread' => NotificationService::unreadCount((int) $request->user()['id'])]);
    }

    public function read(Request $request, array $params): Response
    {
        return Response::ok(NotificationService::markRead((int) $params['id'], (int) $request->user()['id']));
    }

    public function readAll(Request $request, array $params): Response
    {
        return Response::ok(NotificationService::markAllRead((int) $request->user()['id']));
    }
}
