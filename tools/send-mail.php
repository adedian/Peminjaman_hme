<?php
declare(strict_types=1);

/**
 * Kirim ulang email yang tertunda di antrean (mail_outbox).
 * Email biasanya sudah terkirim otomatis setelah setiap aksi; skrip ini untuk percobaan ulang bila SMTP sempat gagal.
 *
 *   php tools/send-mail.php            kirim yang jatuh tempo (maks. 50)
 *
 * Cron (opsional, mis. tiap 10 menit):  *\/10 * * * *  php /path/ke/aplikasi/tools/send-mail.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\MailService;

$r = MailService::drain(50);
fwrite(STDOUT, sprintf("Terkirim: %d, dicoba ulang nanti: %d, gagal permanen: %d (driver: %s)\n", $r['sent'], $r['retry'], $r['failed'], MailService::driver()));
exit($r['failed'] > 0 ? 1 : 0);
