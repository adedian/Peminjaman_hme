<?php
declare(strict_types=1);

/**
 * Front controller tunggal: semua halaman dan API (/api/v1/*) masuk lewat sini.
 * Hanya folder public/ yang boleh menjadi document root di hosting.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\ErrorHandler;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\ForcePasswordChangeMiddleware;
use App\Middleware\RoleMiddleware;
use App\Services\MailService;

header_remove('X-Powered-By');

$router = new Router([
    'auth' => AuthMiddleware::class,
    'role' => RoleMiddleware::class,
    'csrf' => CsrfMiddleware::class,
    'pwd'  => ForcePasswordChangeMiddleware::class,
]);
require HME_ROOT . '/routes/api.php';
require HME_ROOT . '/routes/web.php';

$request = null;
try {
    $request = Request::fromGlobals();
    // Mode pemeliharaan (php tools/maintenance-mode.php on): semua permintaan 503 kecuali /api/v1/health.
    if (is_file(HME_ROOT . '/storage/maintenance.flag') && $request->path() !== '/api/v1/health') {
        throw new HttpException(503, 'maintenance', 'Sistem sedang dalam pemeliharaan. Coba lagi beberapa menit lagi.', [], ['Retry-After' => '120']);
    }
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    $response = ErrorHandler::toResponse($e, $request);
}

$response = $response
    ->withSecurityHeaders($request)
    ->withHeader('X-Request-Id', Logger::requestId());

// Ada email baru di antrean → selesaikan respons ke browser dulu, baru kirim email (pengguna tidak menunggu SMTP).
$hasMail = MailService::hasQueued();
$hasPush = \App\Services\PushService::hasQueued();
$deferMail = $hasMail || $hasPush;
if ($deferMail && !ini_get('zlib.output_compression') && $response->header('Content-Length') === null
    && $response->status() !== 204 && $response->status() !== 304) {
    $response = $response->withHeader('Content-Length', (string) strlen($response->body()));
}
$response->send();

if ($deferMail) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();          // jangan menahan kunci sesi selama mengirim email
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
    if ($hasMail) {
        MailService::drain();
    }
    if ($hasPush) {
        \App\Services\PushService::drain(10, 20);
    }
}
