<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Konfigurasi aplikasi. Semua nilai yang berbeda antara development dan production
 * dibaca dari .env — JANGAN menaruh credential di file ini.
 * Default aman: bila .env tidak ada, aplikasi berjalan sebagai "production".
 */
return [
    'app' => [
        'name'        => 'Peminjaman HME',
        'env'         => Env::get('APP_ENV', 'production'),
        // APP_DEBUG diabaikan di production (lihat Config::isDebug)
        'debug'       => Env::bool('APP_DEBUG', false),
        'base_path'   => rtrim((string) Env::get('APP_BASE_PATH', ''), '/'),
        'timezone'    => 'Asia/Jakarta',
        'trust_proxy' => Env::bool('TRUST_PROXY', false),
    ],

    'db' => [
        'host'    => Env::get('DB_HOST', '127.0.0.1'),
        'port'    => Env::int('DB_PORT', 3306),
        'name'    => Env::get('DB_NAME', 'hme_peminjaman'),
        'user'    => Env::get('DB_USER', 'root'),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
        // READ COMMITTED: pengecekan ketersediaan di bawah kunci selalu melihat data terkomit terbaru dan tanpa gap-lock
        // (mengurangi deadlock). Ganti ke 'REPEATABLE READ' lewat DB_ISOLATION bila hosting mewajibkan.
        'isolation' => Env::get('DB_ISOLATION', 'READ COMMITTED'),
        // Zona waktu sesi database; selaras dengan app.timezone
        'tz'      => '+07:00',
    ],

    'session' => [
        'name'             => 'HME_SESSION',
        'idle_timeout'     => Env::int('SESSION_IDLE_MINUTES', 120) * 60,
        'absolute_timeout' => Env::int('SESSION_ABSOLUTE_HOURS', 12) * 3600,
        // auto = Secure bila request HTTPS
        'secure'           => Env::get('SESSION_SECURE', 'auto'),
    ],

    'realtime' => [
        'mode'        => Env::get('REALTIME_MODE', 'poll'), // poll | sse
        'interval_ms' => Env::int('REALTIME_INTERVAL_MS', 1500),
    ],

    // Email (BR-12/Q8). Tanpa MAIL_HOST → email dilewati & dicatat di log (transaksi tetap jalan).
    // MAIL_DRIVER: smtp | log (tulis ke mail.log, untuk development/tes) | none
    'mail' => [
        'driver'     => Env::get('MAIL_DRIVER', (string) Env::get('MAIL_HOST', '') !== '' ? 'smtp' : 'none'),
        'host'       => Env::get('MAIL_HOST', ''),
        'port'       => Env::int('MAIL_PORT', 587),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),   // tls | ssl | none
        'username'   => Env::get('MAIL_USERNAME', ''),
        'password'   => Env::get('MAIL_PASSWORD', ''),
        'from'       => Env::get('MAIL_FROM_ADDRESS', ''),
        'from_name'  => Env::get('MAIL_FROM_NAME', 'Sistem Peminjaman HME'),
        'timeout'    => Env::int('MAIL_TIMEOUT', 10),
    ],

    'upload' => [
        'max_bytes' => Env::int('UPLOAD_MAX_BYTES', 3 * 1024 * 1024),
    ],

    'paths' => [
        'root'    => HME_ROOT,
        'storage' => HME_ROOT . '/storage',
        // LOG_DIR memungkinkan log disimpan di luar folder aplikasi (disarankan di production)
        'logs'    => Env::get('LOG_DIR', HME_ROOT . '/storage/logs'),
        // UPLOAD_DIR memungkinkan foto disimpan di luar folder aplikasi (disarankan di production)
        'uploads' => Env::get('UPLOAD_DIR', HME_ROOT . '/storage/uploads'),
        'views'   => HME_ROOT . '/app/Views',
    ],
];
