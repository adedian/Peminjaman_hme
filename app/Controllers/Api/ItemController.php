<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ItemService;

/** Semua route berada di grup auth + pwd + role:admin (lihat routes/api.php). */
final class ItemController
{
    public function categories(Request $request, array $params): Response
    {
        return Response::ok(ItemService::categories());
    }

    public function index(Request $request, array $params): Response
    {
        $q = static fn(string $k) => $request->query($k);
        $filters = array_filter([
            'q' => $q('q'), 'category_id' => $q('category_id'), 'status' => $q('status'), 'active' => $q('active'),
        ], static fn($v) => $v !== null && $v !== '');
        foreach ($filters as $v) {
            if (!is_string($v)) {
                throw new HttpException(422, 'validation_failed', 'Filter tidak valid.');
            }
        }
        $int = static function ($v, int $default): int {
            return is_string($v) && ctype_digit($v) ? (int) $v : $default;
        };
        return Response::ok(ItemService::list($filters, $int($q('limit'), 100), $int($q('offset'), 0)));
    }

    public function show(Request $request, array $params): Response
    {
        $item = ItemService::find((int) $params['id']);
        if ($item === null) {
            throw new HttpException(404, 'not_found', 'Barang tidak ditemukan.');
        }
        return Response::ok($item);
    }

    public function store(Request $request, array $params): Response
    {
        return Response::ok(ItemService::create($request->body(), $request->file('photo'), $request->user() ?? [], $request), 201);
    }

    /** POST (bukan PUT) karena PHP tidak mengurai multipart untuk PUT — foto dikirim bersama field. */
    public function update(Request $request, array $params): Response
    {
        return Response::ok(ItemService::update((int) $params['id'], $request->body(), $request->file('photo'), $request->user() ?? [], $request));
    }

    public function activate(Request $request, array $params): Response
    {
        return Response::ok(ItemService::setActive((int) $params['id'], true, $request->user() ?? [], $request));
    }

    public function deactivate(Request $request, array $params): Response
    {
        return Response::ok(ItemService::setActive((int) $params['id'], false, $request->user() ?? [], $request));
    }
}
