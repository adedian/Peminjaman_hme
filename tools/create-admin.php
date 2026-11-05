<?php
declare(strict_types=1);

/**
 * Membuat akun admin (pertama atau tambahan) dari terminal. TIDAK ADA password bawaan.
 *
 *   php tools/create-admin.php --username=admin --name="Admin HME"
 *       → meminta password di terminal (diketik dua kali)
 *   php tools/create-admin.php --username=admin --name="Admin HME" --generate
 *       → membuat password acak dan menampilkannya SEKALI
 *   Password dari variabel lingkungan (untuk otomasi):  HME_ADMIN_PASSWORD=... php tools/create-admin.php --username=...
 *
 * Akun baru wajib mengganti password saat login pertama. Password tidak boleh diberikan lewat argumen
 * baris perintah karena tersimpan di riwayat shell dan terlihat di daftar proses.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use App\Services\UserService;

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (isset($opts['password'])) {
    fwrite(STDERR, "Ditolak: jangan berikan password lewat argumen (--password). Gunakan prompt, --generate, atau HME_ADMIN_PASSWORD.\n");
    exit(1);
}

$username = mb_strtolower(trim((string) ($opts['username'] ?? '')));
$name = trim((string) ($opts['name'] ?? ''));
if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username) || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    fwrite(STDERR, "Penggunaan: php tools/create-admin.php --username=<3-50 huruf/angka/._-> --name=\"Nama Lengkap\" [--generate]\n");
    exit(1);
}

$plain = getenv('HME_ADMIN_PASSWORD') ?: '';
$generated = false;
if (isset($opts['generate'])) {
    $plain = PasswordPolicy::generate();
    $generated = true;
} elseif ($plain === '') {
    // Prompt interaktif (tidak ada penyembunyian karakter yang portabel di Windows PHP CLI)
    fwrite(STDOUT, "Password untuk '{$username}' (min " . PasswordPolicy::minLength() . " karakter, akan terlihat saat diketik): ");
    $plain = trim((string) fgets(STDIN));
    fwrite(STDOUT, 'Ulangi password: ');
    $again = trim((string) fgets(STDIN));
    if ($plain !== $again) {
        fwrite(STDERR, "Password tidak sama. Dibatalkan.\n");
        exit(1);
    }
}

$err = PasswordPolicy::check($plain, $username);
if ($err !== null) {
    fwrite(STDERR, "Password ditolak: {$err}\n");
    exit(1);
}

try {
    if (Database::value('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
        fwrite(STDERR, "Username '{$username}' sudah ada.\n");
        exit(1);
    }
    $id = Database::insert('users', [
        'username'             => $username,
        'password_hash'        => password_hash($plain, PASSWORD_DEFAULT),
        'name'                 => $name,
        'initials'             => UserService::initialsFor($name),
        'role'                 => 'admin',
        'must_change_password' => 0,
    ]);
    AuditService::log('user.created', null, 'user', $id, null, ['username' => $username, 'role' => 'admin', 'via' => 'cli']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Admin '{$username}' dibuat (ID {$id}).\n";
if ($generated) {
    echo "Password sementara (hanya ditampilkan sekali): {$plain}\n";
}
exit(0);
