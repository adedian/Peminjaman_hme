<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\ItemService;

final class ItemPageController extends BaseController
{
    public function index(Request $request, array $params): Response
    {
        $list = ItemService::list([], 200, 0);
        return $this->page($request, 'items', 'items', 'Master Barang', [
            'items'      => $list['items'],
            'total'      => $list['total'],
            'categories' => ItemService::categories(),
        ]);
    }
}
