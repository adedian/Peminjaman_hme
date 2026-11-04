<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Render template PHP dari app/Views. Template hanya menampilkan data;
 * semua nilai dinamis WAJIB dilewatkan e() (helper di app/Helpers/functions.php).
 */
final class View
{
    /** @var array<string,mixed> data yang dibagikan dari view konten ke layout (mis. skrip halaman, sheet) */
    private static array $shared = [];

    /** @param mixed $value */
    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param mixed $default @return mixed */
    public static function shared(string $key, $default = null)
    {
        return self::$shared[$key] ?? $default;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        if ($layout !== null) {
            self::$shared = []; // halaman baru → bagian bersama dimulai bersih
        }
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function response(string $view, array $data = [], int $status = 200, ?string $layout = 'layout'): Response
    {
        return Response::html(self::render($view, $data, $layout), $status);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $view, array $data): string
    {
        if (!preg_match('#^[A-Za-z0-9_/\-]+$#', $view)) {
            throw new \InvalidArgumentException('Nama view tidak valid.');
        }
        $file = (string) Config::get('paths.views') . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View tidak ditemukan: {$view}");
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
