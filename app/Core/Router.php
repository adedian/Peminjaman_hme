<?php
declare(strict_types=1);

namespace App\Core;

use Closure;

final class Router
{
    /** @var array<int,array{method:string,path:string,regex:string,handler:mixed,middleware:array<int,string>}> */
    private array $routes = [];
    /** @var array<int,array{prefix:string,middleware:array<int,string>}> */
    private array $stack = [];
    /** @var array<string,class-string> alias → kelas middleware */
    private array $aliases = [];

    /** @param array<string,class-string> $aliases */
    public function __construct(array $aliases = [])
    {
        $this->aliases = $aliases;
    }

    /** @param mixed $handler  Closure atau [KelasController::class, 'metode'] @param array<int,string> $middleware */
    public function add(string $method, string $pattern, $handler, array $middleware = []): void
    {
        $prefix = '';
        $mw = [];
        foreach ($this->stack as $g) {
            $prefix .= $g['prefix'];
            $mw = array_merge($mw, $g['middleware']);
        }
        $full = self::normalize($prefix . $pattern);
        $method = strtoupper($method);
        $all = array_merge($mw, $middleware);

        // CSRF otomatis untuk SETIAP method yang mengubah data (tidak bisa lupa dipasang).
        // Diletakkan setelah 'auth' bila ada, supaya sesi kedaluwarsa menghasilkan 401, bukan 403.
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !in_array('csrf', $all, true)) {
            $pos = 0;
            foreach ($all as $i => $spec) {
                if ($spec === 'auth') {
                    $pos = $i + 1;
                    break;
                }
            }
            array_splice($all, $pos, 0, ['csrf']);
        }

        $this->routes[] = [
            'method'     => $method,
            'path'       => $full,
            'regex'      => self::compile($full),
            'handler'    => $handler,
            'middleware' => $all,
        ];
    }

    /** @param mixed $h @param array<int,string> $mw */
    public function get(string $p, $h, array $mw = []): void    { $this->add('GET', $p, $h, $mw); }
    /** @param mixed $h @param array<int,string> $mw */
    public function post(string $p, $h, array $mw = []): void   { $this->add('POST', $p, $h, $mw); }
    /** @param mixed $h @param array<int,string> $mw */
    public function put(string $p, $h, array $mw = []): void    { $this->add('PUT', $p, $h, $mw); }
    /** @param mixed $h @param array<int,string> $mw */
    public function delete(string $p, $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    /** @param array{prefix?:string,middleware?:array<int,string>} $opts */
    public function group(array $opts, callable $fn): void
    {
        $this->stack[] = ['prefix' => $opts['prefix'] ?? '', 'middleware' => $opts['middleware'] ?? []];
        try {
            $fn($this);
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * Daftar rute terdaftar (untuk tes matriks otorisasi & dokumentasi). Tidak dipakai saat melayani request.
     *
     * @return array<int,array{method:string,path:string,middleware:array<int,string>}>
     */
    public function routes(): array
    {
        return array_map(static fn(array $r): array => ['method' => $r['method'], 'path' => $r['path'], 'middleware' => $r['middleware']], $this->routes);
    }

    public function dispatch(Request $request): Response
    {
        $path = self::normalize($request->path());
        $method = $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $matches = $route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET');
            if (!$matches) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            return $this->runPipeline($route['middleware'], $route['handler'], $request, $params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'method_not_allowed', 'Metode tidak diizinkan.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'not_found', 'Halaman tidak ditemukan.');
    }

    /**
     * @param array<int,string> $middleware
     * @param mixed $handler
     * @param array<string,string> $params
     */
    private function runPipeline(array $middleware, $handler, Request $request, array $params): Response
    {
        $core = function (Request $req) use ($handler, $params): Response {
            if ($handler instanceof Closure) {
                $res = $handler($req, $params);
            } else {
                [$class, $method] = $handler;
                $res = (new $class())->$method($req, $params);
            }
            if (!$res instanceof Response) {
                throw new \LogicException('Handler harus mengembalikan Response.');
            }
            return $res;
        };

        $next = $core;
        foreach (array_reverse($middleware) as $spec) {
            $next = $this->wrap($spec, $next);
        }
        return $next($request);
    }

    private function wrap(string $spec, callable $next): Closure
    {
        $name = $spec;
        $args = [];
        if (strpos($spec, ':') !== false) {
            [$name, $argStr] = explode(':', $spec, 2);
            $args = $argStr === '' ? [] : explode(',', $argStr);
        }
        if (!isset($this->aliases[$name])) {
            throw new \LogicException("Middleware '{$name}' tidak terdaftar.");
        }
        $class = $this->aliases[$name];
        return static function (Request $req) use ($class, $args, $next): Response {
            /** @var Middleware\MiddlewareInterface $mw */
            $mw = new $class(...$args);
            return $mw->handle($req, $next);
        };
    }

    private static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    private static function compile(string $pattern): string
    {
        $regex = '';
        $offset = 0;
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}/', $pattern, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($mm as $m) {
            $regex .= preg_quote(substr($pattern, $offset, $m[0][1] - $offset), '#');
            $regex .= '(?P<' . $m[1][0] . '>' . (isset($m[2]) ? $m[2][0] : '[^/]+') . ')';
            $offset = $m[0][1] + strlen($m[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $offset), '#');
        return '#^' . $regex . '$#';
    }
}
