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
        // Aktif dan tersembunyi diambil terpisah: barang yang disembunyikan dari daftar pinjam tetap dapat ditemukan
        // (filter "Tersembunyi") walau jumlah barang aktif melebihi batas halaman.
        $active = ItemService::list(['active' => '1'], 200, 0);
        $hidden = ItemService::list(['active' => '0'], 200, 0);
        return $this->page($request, 'items', 'items', 'Master Barang', [
            'items'      => array_merge($active['items'], $hidden['items']),
            'total'      => $active['total'] + $hidden['total'],
            'categories' => ItemService::categories(),
        ]);
    }
}
