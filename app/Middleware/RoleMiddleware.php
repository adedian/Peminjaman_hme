<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Pembatasan berdasarkan role. Dipakai sebagai 'role:admin' atau 'role:admin,staff'. Harus berjalan SETELAH 'auth'. */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @var array<int,string> */
    private array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new HttpException(401, 'unauthenticated', 'Silakan masuk terlebih dahulu.');
        }
        if (!in_array($user['role'], $this->roles, true)) {
            throw new HttpException(403, 'forbidden', 'Anda tidak memiliki akses ke fitur ini.');
        }
        return $next($request);
    }
}
