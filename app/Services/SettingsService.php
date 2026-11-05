<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Models\Setting;
use Throwable;

/**
 * Pengaturan yang boleh diubah admin dari aplikasi (Q8): alamat email penerima notifikasi admin.
 * Credential SMTP sengaja TIDAK disimpan di database / tidak dapat diubah dari web — hanya di .env.
 */
final class SettingsService
{
    private const MAX_LEN = 500;

    /** @return array<string,mixed> */
    public static function show(): array
    {
        return [
            'admin_notification_email' => (string) Setting::get('admin_notification_email', ''),
            'recipients'               => MailService::adminRecipients(),
            'mail'                     => MailService::status(),
            // Notifikasi push (PWA) + aturan waktu peminjaman/PQA
            'push_enabled'             => Setting::get('push_enabled', '1') !== '0',
            'push'                     => PushService::status(),
            'loan_max_days'            => Setting::int('loan_max_days', 30),
            'loan_reminder_hours'      => Setting::int('loan_reminder_hours', 24),
            'pqa_reminder_hours'       => Setting::int('pqa_reminder_hours', 24),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function update(array $input, array $actor, Request $request): array
    {
        $others = ['push_enabled', 'loan_max_days', 'loan_reminder_hours', 'pqa_reminder_hours'];
        $hasOther = array_intersect($others, array_keys($input)) !== [];
        if ($hasOther) {
            self::updateOthers($input, $actor, $request);
            if (!array_key_exists('admin_notification_email', $input)) {
                return self::show();
            }
        }
        $raw = $input['admin_notification_email'] ?? null;
        if (!is_string($raw)) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['admin_notification_email' => 'Isi minimal satu alamat email.']);
        }
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > self::MAX_LEN) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['admin_notification_email' => 'Isi 1–10 alamat email, dipisah koma.']);
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []), static fn(string $p): bool => $p !== ''));
        $bad = array_values(array_filter($parts, static fn(string $p): bool => filter_var($p, FILTER_VALIDATE_EMAIL) === false));
        if ($bad !== []) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['admin_notification_email' => 'Alamat tidak valid: ' . mb_substr(implode(', ', $bad), 0, 120)]);
        }
        $list = MailService::parseList($raw);
        if (count($parts) > 10) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', ['admin_notification_email' => 'Maksimal 10 alamat email.']);
        }
        $before = (string) Setting::get('admin_notification_email', '');
        $value = implode(', ', $list);
        Setting::set('admin_notification_email', $value);
        AuditService::log('settings.updated', (int) $actor['id'], 'setting', 'admin_notification_email',
            ['admin_notification_email' => $before], ['admin_notification_email' => $value], $request);
        return self::show();
    }

    /**
     * Saklar notifikasi push dan jendela pengingat/lama pinjam. Semua divalidasi dulu; tidak ada yang tersimpan
     * bila salah satu tidak valid.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $actor
     */
    private static function updateOthers(array $input, array $actor, Request $request): void
    {
        $errors = [];
        $set = [];
        if (array_key_exists('push_enabled', $input)) {
            $v = $input['push_enabled'];
            if (!is_bool($v) && !in_array($v, [0, 1, '0', '1'], true)) {
                $errors['push_enabled'] = 'Nilai tidak valid.';
            } else {
                $set['push_enabled'] = ($v === true || $v === 1 || $v === '1') ? '1' : '0';
            }
        }
        foreach (['loan_max_days' => [1, 365], 'loan_reminder_hours' => [1, 168], 'pqa_reminder_hours' => [1, 168]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $input)) {
                $v = $input[$k];
                if ((!is_int($v) && !(is_string($v) && ctype_digit($v))) || (int) $v < $lo || (int) $v > $hi) {
                    $errors[$k] = "Isi angka {$lo}–{$hi}.";
                } else {
                    $set[$k] = (string) (int) $v;
                }
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', $errors);
        }
        foreach ($set as $k => $v) {
            $before = (string) Setting::get($k, '');
            if ($before !== $v) {
                Setting::set($k, $v);
                AuditService::log('settings.updated', (int) $actor['id'], 'setting', $k, [$k => $before], [$k => $v], $request);
            }
        }
    }

    /**
     * Kirim email uji langsung (tanpa antrean) ke alamat admin, agar konfigurasi SMTP bisa dicek.
     *
     * @param array<string,mixed> $actor
     * @return array{sent_to:array<int,string>}
     */
    public static function testEmail(array $actor, Request $request): array
    {
        $to = MailService::adminRecipients();
        if ($to === []) {
            throw new HttpException(422, 'validation_failed', 'Alamat email admin belum diatur.', ['admin_notification_email' => 'Isi alamat email lalu simpan.']);
        }
        try {
            MailService::sendNow($to, 'Email Uji',
                'Ini email uji dari <b>Sistem Peminjaman HME</b>. Bila email ini diterima, notifikasi admin sudah berfungsi.');
        } catch (Throwable $e) {
            Logger::error('Email uji gagal', ['err' => $e->getMessage()]);
            AuditService::log('settings.test_email_failed', (int) $actor['id'], 'setting', 'mail', null, ['to' => $to], $request);
            $msg = $e instanceof \RuntimeException && strpos($e->getMessage(), 'SMTP belum') === 0
                ? $e->getMessage()
                : 'Email uji gagal dikirim. Periksa pengaturan SMTP di .env (detail di log server).';
            throw new HttpException(502, 'mail_failed', $msg);
        }
        AuditService::log('settings.test_email', (int) $actor['id'], 'setting', 'mail', null, ['to' => $to], $request);
        return ['sent_to' => $to];
    }
}
