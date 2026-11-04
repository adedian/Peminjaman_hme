<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class BaseController
{
    /**
     * Halaman dengan shell aplikasi (navigasi sesuai peran).
     *
     * @param array<string,mixed> $data
     */
    protected function page(Request $request, string $view, string $nav, string $title, array $data = [], int $status = 200): Response
    {
        return View::response($view, $data + [
            'user'  => $request->user(),
            'nav'   => $nav,
            'title' => $title,
        ], $status, 'layout_app');
    }
}
