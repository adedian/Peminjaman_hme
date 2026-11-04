<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Setting;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

/**
 * Email notifikasi admin (BR-12 / Q8) lewat antrean `mail_outbox`.
 *
 *  - queue()  dipanggil notifier SETELAH transaksi bisnis dikomit; hanya menulis baris antrean.
 *  - drain()  mengirim antrean yang jatuh tempo. Dipanggil front controller setelah respons dikirim ke browser
 *             (pengguna tidak menunggu SMTP) dan oleh tools/send-mail.php (cron) untuk percobaan ulang.
 *  - SMTP belum dikonfigurasi (driver "none") → email ditandai "skipped" dan dicatat di log; transaksi tetap berhasil.
 *  - Gagal kirim → dicoba ulang maksimal MAX_ATTEMPTS kali dengan jeda bertahap, lalu "failed".
 *  - Isi dari pengguna (nama, lokasi, nama barang) selalu di-escape di badan HTML.
 */
final class MailService
{
    public const MAX_ATTEMPTS = 5;
    private const SUBJECT_PREFIX = '[INVENTORY HME] ';
    private const MAX_RECIPIENTS = 10;

    private static bool $queued = false;

    // ======================================================================================
    //  Penerima
    // ======================================================================================

    /** Alamat admin dari settings.admin_notification_email (dipisah koma/titik koma/spasi), valid & unik. @return array<int,string> */
    public static function adminRecipients(): array
    {
        return self::parseList((string) Setting::get('admin_notification_email', ''));
    }

    /** @return array<int,string> */
    public static function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            $p = strtolower(trim($p));
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL) !== false && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return array_slice($out, 0, self::MAX_RECIPIENTS);
    }

    public static function driver(): string
    {
        $d = strtolower((string) Config::get('mail.driver', 'none'));
        return in_array($d, ['smtp', 'log', 'none'], true) ? $d : 'none';
    }

    // ======================================================================================
    //  Antrean
    // ======================================================================================

    /**
     * Masukkan email ke antrean. Tidak pernah melempar exception (kegagalan hanya dicatat).
     *
     * @param array<int,string> $to
     * @return int|null id antrean
     */
    public static function queue(array $to, string $subject, string $bodyHtml, string $event): ?int
    {
        try {
            if ($to === []) {
                Logger::warning('Email tidak dikirim: alamat admin belum diatur', ['event' => $event]);
                return null;
            }
            $skip = self::driver() === 'none';
            $id = Database::insert('mail_outbox', [
                'event'      => substr($event, 0, 40),
                'recipients' => implode(',', $to),
                'subject'    => mb_substr(self::SUBJECT_PREFIX . $subject, 0, 200),
                'html_body'  => self::layout($bodyHtml),
                'text_body'  => self::toText($bodyHtml),
                'status'     => $skip ? 'skipped' : 'pending',
                'last_error' => $skip ? 'SMTP belum dikonfigurasi (MAIL_HOST kosong)' : null,
            ]);
            if ($skip) {
                Logger::info('Email dilewati: SMTP belum dikonfigurasi', ['event' => $event, 'outbox_id' => $id]);
            } else {
                self::$queued = true;
            }
            return $id;
        } catch (Throwable $e) {
            Logger::error('Email gagal masuk antrean', ['event' => $event, 'err' => $e->getMessage()]);
            return null;
        }
    }

    /** Apakah request ini menambah email ke antrean (front controller lalu mengirimnya setelah respons). */
    public static function hasQueued(): bool
    {
        return self::$queued;
    }

    /**
     * Kirim email yang jatuh tempo. Aman dijalankan paralel: setiap baris "diklaim" dulu dengan UPDATE bersyarat.
     *
     * @return array{sent:int,failed:int,retry:int}
     */
    public static function drain(int $limit = 10): array
    {
        self::$queued = false;
        $res = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        try {
            $rows = Database::all(
                "SELECT id FROM mail_outbox WHERE status = 'pending' AND next_attempt_at <= NOW()
                   AND (locked_until IS NULL OR locked_until < NOW()) ORDER BY id LIMIT ?",
                [max(1, $limit)]
            );
        } catch (Throwable $e) {
            Logger::error('Antrean email tidak dapat dibaca', ['err' => $e->getMessage()]);
            return $res;
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $claimed = Database::run(
                "UPDATE mail_outbox SET locked_until = NOW() + INTERVAL 2 MINUTE, attempts = attempts + 1
                 WHERE id = ? AND status = 'pending' AND (locked_until IS NULL OR locked_until < NOW())",
                [$id]
            )->rowCount();
            if ($claimed !== 1) {
                continue;   // diambil proses lain
            }
            $m = Database::one('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
            if ($m === null) {
                continue;
            }
            try {
                self::deliver($m);
                Database::run("UPDATE mail_outbox SET status = 'sent', sent_at = NOW(), locked_until = NULL, last_error = NULL WHERE id = ?", [$id]);
                $res['sent']++;
            } catch (Throwable $e) {
                $err = mb_substr(self::safeError($e), 0, 500);
                $attempts = (int) $m['attempts'];
                if ($attempts >= self::MAX_ATTEMPTS) {
                    Database::run("UPDATE mail_outbox SET status = 'failed', locked_until = NULL, last_error = ? WHERE id = ?", [$err, $id]);
                    $res['failed']++;
                } else {
                    Database::run(
                        'UPDATE mail_outbox SET locked_until = NULL, last_error = ?, next_attempt_at = NOW() + INTERVAL ? MINUTE WHERE id = ?',
                        [$err, $attempts * 5, $id]
                    );
                    $res['retry']++;
                }
                Logger::error('Email gagal dikirim', ['outbox_id' => $id, 'attempt' => $attempts, 'err' => $err]);
            }
        }
        return $res;
    }

    /**
     * Kirim langsung (tanpa antrean) — untuk tombol "Kirim email uji" di Pengaturan.
     *
     * @param array<int,string> $to
     */
    public static function sendNow(array $to, string $subject, string $bodyHtml): void
    {
        if (self::driver() === 'none') {
            throw new \RuntimeException('SMTP belum dikonfigurasi. Isi MAIL_HOST dan kawan-kawan di berkas .env.');
        }
        self::deliver([
            'recipients' => implode(',', $to), 'subject' => self::SUBJECT_PREFIX . $subject,
            'html_body' => self::layout($bodyHtml), 'text_body' => self::toText($bodyHtml), 'event' => 'test',
        ]);
    }

    /** Ringkasan status untuk halaman Pengaturan. @return array<string,mixed> */
    public static function status(): array
    {
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (Database::all('SELECT status, COUNT(*) AS n FROM mail_outbox GROUP BY status') as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $last = Database::one("SELECT last_error, created_at FROM mail_outbox WHERE status IN ('failed','pending') AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1");
        return [
            'driver'     => self::driver(),
            'configured' => self::driver() !== 'none',
            'host'       => self::driver() === 'smtp' ? (string) Config::get('mail.host') : null,
            'from'       => self::fromAddress(),
            'counts'     => $counts,
            'last_error' => $last === null ? null : ['message' => (string) $last['last_error'], 'at' => (string) $last['created_at']],
        ];
    }

    // ======================================================================================
    //  Internal
    // ======================================================================================

    /** @param array<string,mixed> $m baris mail_outbox */
    private static function deliver(array $m): void
    {
        $to = self::parseList((string) $m['recipients']);
        if ($to === []) {
            throw new \RuntimeException('Tidak ada alamat penerima yang valid.');
        }
        if (self::driver() === 'log') {
            $line = json_encode([
                'at' => date('c'), 'to' => $to, 'subject' => (string) $m['subject'], 'event' => $m['event'] ?? null,
                'text' => (string) ($m['text_body'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dir = (string) Config::get('paths.logs');
            if (@file_put_contents($dir . '/mail.log', $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('mail.log tidak dapat ditulis.');
            }
            return;
        }
        if (self::driver() !== 'smtp') {
            throw new \RuntimeException('SMTP belum dikonfigurasi.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) Config::get('mail.host');
        $mail->Port = (int) Config::get('mail.port', 587);
        $enc = strtolower((string) Config::get('mail.encryption', 'tls'));
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $user = (string) Config::get('mail.username', '');
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = (string) Config::get('mail.password', '');
        }
        $mail->Timeout = max(3, (int) Config::get('mail.timeout', 10));
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(self::fromAddress(), (string) Config::get('mail.from_name', 'Sistem Peminjaman HME'));
        foreach ($to as $addr) {
            $mail->addAddress($addr);
        }
        $mail->isHTML(true);
        $mail->Subject = (string) $m['subject'];
        $mail->Body = (string) $m['html_body'];
        $mail->AltBody = (string) ($m['text_body'] ?? '');
        $mail->send();
    }

    private static function fromAddress(): string
    {
        $from = (string) Config::get('mail.from', '');
        if ($from === '') {
            $from = (string) Config::get('mail.username', '');
        }
        return filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? $from : 'no-reply@localhost';
    }

    /** Pesan error tanpa credential. */
    private static function safeError(Throwable $e): string
    {
        $msg = $e instanceof MailerException ? $e->errorMessage() : $e->getMessage();
        $pass = (string) Config::get('mail.password', '');
        if ($pass !== '') {
            $msg = str_replace($pass, '***', $msg);
        }
        return trim(strip_tags($msg)) ?: get_class($e);
    }

    /** Bingkai email sama dengan sistem lama (sendHmeEmail). */
    private static function layout(string $content): string
    {
        return '<div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;">'
            . '<h2 style="color: #010031;">Sistem Peminjaman HME</h2>'
            . '<hr style="border: 0; border-top: 1px solid #eee;">'
            . '<div style="padding: 10px 0; color: #4a5568;">' . $content . '</div>'
            . '<hr style="border: 0; border-top: 1px solid #eee;">'
            . '<p style="font-size: 11px; color: #a0aec0;">Email otomatis. Mohon tidak membalas.</p>'
            . '</div>';
    }

    private static function toText(string $html): string
    {
        $t = preg_replace(['#<br\s*/?>#i', '#</li>#i', '#<li[^>]*>#i', '#</(p|div|ul|h2)>#i'], ["\n", "\n", '- ', "\n"], $html) ?? $html;
        return trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
