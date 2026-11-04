<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sesi server dengan pengamanan:
 *  - cookie HttpOnly + SameSite=Lax (+ Secure di HTTPS), mode strict (ID tak dikenal ditolak)
 *  - file sesi disimpan di storage/sessions (bukan folder tmp bersama)
 *  - timeout idle dan absolut
 *  - ID sesi diganti (regenerate) saat login/logout/ganti password → mencegah session fixation
 * Sesi dimulai malas (lazy): endpoint publik seperti /health tidak membuat file sesi.
 */
final class Session
{
    private static bool $started = false;

    public static function isStarted(): bool
    {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }

    /** Apakah browser mengirim cookie sesi? Tanpa cookie, tidak ada sesi yang perlu dibuka/dibuat. */
    public static function hasCookie(): bool
    {
        return isset($_COOKIE[(string) Config::get('session.name', 'HME_SESSION')]);
    }

    /**
     * @param bool $touch false untuk polling realtime: tidak memperpanjang timeout idle,
     *                    sehingga tab yang dibiarkan terbuka tetap berakhir sesuai idle_timeout.
     */
    public static function start(?Request $request = null, bool $touch = true): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $dir = (string) Config::get('paths.storage') . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $cfg = Config::get('session.secure', 'auto');
        if ($cfg === 'auto') {
            $secure = $request !== null
                ? $request->isSecure()
                : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        } else {
            $secure = filter_var($cfg, FILTER_VALIDATE_BOOLEAN);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max(
            (int) Config::get('session.idle_timeout', 7200),
            3600
        ));

        session_name((string) Config::get('session.name', 'HME_SESSION'));
        session_save_path($dir);
        $path = (string) Config::get('app.base_path', '');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $path === '' ? '/' : $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new HttpException(500, 'session_failed', 'Sesi tidak dapat dimulai.');
        }
        self::$started = true;

        $now = time();
        $idle = (int) Config::get('session.idle_timeout', 7200);
        $abs = (int) Config::get('session.absolute_timeout', 43200);

        if (isset($_SESSION['_created']) && ($now - (int) $_SESSION['_created']) > $abs) {
            self::reset();
        } elseif (isset($_SESSION['_last']) && ($now - (int) $_SESSION['_last']) > $idle) {
            self::reset();
        }
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = $now;
        }
        if ($touch || !isset($_SESSION['_last'])) {
            $_SESSION['_last'] = $now;
        }
    }

    /** Kosongkan isi sesi dan ganti ID (dipakai saat timeout/logout). */
    public static function reset(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
        $_SESSION['_last'] = time();
    }

    /** Dipanggil setelah autentikasi berhasil. */
    public static function login(int $userId, int $authVersion = 1): void
    {
        self::start();
        session_regenerate_id(true);
        $keepFlash = $_SESSION['_flash'] ?? null;
        $_SESSION = ['_created' => time(), '_last' => time(), 'user_id' => $userId, 'auth_version' => $authVersion];
        if ($keepFlash !== null) {
            $_SESSION['_flash'] = $keepFlash;
        }
        Csrf::regenerate();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    public static function userId(): ?int
    {
        self::start();
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    /** Versi kredensial yang tersimpan saat login (lihat users.auth_version). */
    public static function authVersion(): int
    {
        self::start();
        return (int) ($_SESSION['auth_version'] ?? 0);
    }

    /** Ganti ID sesi tanpa mengubah isi (setelah ganti password / naik hak akses). */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    /** Lepas kunci file sesi agar request lain dari pengguna yang sama tidak menunggu. */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** @param mixed $value */
    public static function flash(string $key, $value): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $value;
    }

    /** @return mixed */
    public static function pullFlash(string $key, $default = null)
    {
        self::start();
        $v = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}
