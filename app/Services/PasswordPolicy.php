<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Kebijakan password (keputusan Q7): minimal 8 karakter (dapat diatur di settings.password_min_length).
 * Batas atas 72 BYTE karena bcrypt memotong lebih dari itu secara diam-diam.
 * Tanpa aturan kompleksitas yang menyulitkan pengguna di HP, tetapi menolak password yang jelas lemah.
 */
final class PasswordPolicy
{
    public const MAX_BYTES = 72;

    private const COMMON = [
        '12345678', '123456789', '1234567890', '87654321', 'password', 'password1', 'passw0rd', 'qwertyui', 'qwerty123',
        'abcd1234', 'abc12345', '11111111', '00000000', 'iloveyou', 'admin123', 'admin1234', 'hme12345', 'hexamulti',
    ];

    public static function minLength(): int
    {
        return max(8, Setting::int('password_min_length', 8));
    }

    /** @return string|null pesan kesalahan, atau null bila password dapat diterima */
    public static function check(string $password, ?string $username = null): ?string
    {
        if (mb_strlen($password) < self::minLength()) {
            return 'Password minimal ' . self::minLength() . ' karakter.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'Password terlalu panjang (maksimal ' . self::MAX_BYTES . ' karakter).';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || count(array_unique(mb_str_split($lower))) === 1) {
            return 'Password terlalu mudah ditebak. Gunakan kombinasi yang lebih unik.';
        }
        if ($username !== null && $username !== '' && $lower === mb_strtolower($username)) {
            return 'Password tidak boleh sama dengan username.';
        }
        return null;
    }

    /** Password sementara acak (tanpa karakter yang mudah tertukar: 0 O 1 l I). */
    public static function generate(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        do {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[random_int(0, $max)];
            }
            // pastikan ada huruf kecil, besar, dan angka
        } while (!preg_match('/[a-z]/', $out) || !preg_match('/[A-Z]/', $out) || !preg_match('/\d/', $out));
        return $out;
    }
}
