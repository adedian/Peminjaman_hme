<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

final class ProfileController extends BaseController
{
    public function show(Request $request, array $params): Response
    {
        return $this->page($request, 'profile', 'profile', 'Profil', [
            'errors' => Session::pullFlash('profile_errors', []),
            'forced' => (bool) $request->user()['must_change_password'],
        ]);
    }

    /** Fallback tanpa JavaScript untuk formulir ganti password. */
    public function changePassword(Request $request, array $params): Response
    {
        $s = static function (string $k) use ($request): string {
            $v = $request->input($k);
            return is_string($v) ? $v : '';
        };
        try {
            AuthService::changePassword((int) $request->user()['id'], $s('current'), $s('new'), $s('confirm'), $request);
        } catch (HttpException $e) {
            if ($e->status() !== 422 && $e->status() !== 429) {
                throw $e;
            }
            Session::flash('profile_errors', $e->fields() ?: ['current' => $e->getMessage()]);
            return Response::redirect(url('/profile/password'), 303);
        }
        Session::flash('toast', ['title' => 'Password diperbarui', 'sub' => 'Gunakan password baru saat masuk berikutnya.', 'kind' => 'ok']);
        return Response::redirect(url('/'), 303);
    }
}
