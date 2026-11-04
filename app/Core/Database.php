<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Pembungkus PDO. Semua query WAJIB lewat prepared statement bernilai terikat (bound).
 * - Prepared statement asli (bukan emulasi) → mencegah SQL injection di level driver.
 * - Mode SQL ketat → data tidak valid ditolak, bukan dipotong diam-diam.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(
                (string) Config::get('db.name'),
                true
            );
        }
        return self::$pdo;
    }

    /** Dipakai migrator untuk membuat database (dbName = null → tanpa memilih database). */
    public static function connect(?string $dbName, bool $withDb = true, array $extraOptions = []): PDO
    {
        $host = (string) Config::get('db.host');
        $port = (int) Config::get('db.port');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if ($withDb && $dbName !== null && $dbName !== '') {
            $dsn .= ';dbname=' . $dbName;
        }
        $tz = (string) Config::get('db.tz', '+07:00');
        $sqlMode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

        try {
            $pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci, time_zone = '{$tz}', sql_mode = '{$sqlMode}'",
            ] + $extraOptions);
            $iso = strtoupper((string) Config::get('db.isolation', 'READ COMMITTED'));
            if (in_array($iso, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $iso);
            }
            return $pdo;
        } catch (PDOException $e) {
            // Jangan bocorkan DSN/credential ke pengguna; detail hanya ke log.
            Logger::error('Koneksi database gagal', ['code' => $e->getCode(), 'msg' => $e->getMessage()]);
            throw new HttpException(503, 'db_unavailable', 'Database tidak dapat dihubungi.');
        }
    }

    /**
     * Nilai di-bind sesuai tipe PHP-nya (int → PARAM_INT, null → NULL, bool → PARAM_BOOL).
     * Tanpa ini semua nilai dikirim sebagai string, sehingga `LIMIT ?` gagal dan perbandingan
     * kolom integer memakai konversi implisit MySQL.
     *
     * @param array<int|string,mixed> $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return mixed */
    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $data  kolom => nilai (nama kolom divalidasi) */
    public static function insert(string $table, array $data): int
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        foreach ($cols as $c) {
            self::assertIdent((string) $c);
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdent($table);
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('update() butuh data dan kondisi.');
        }
        $set = [];
        $params = [];
        foreach ($data as $c => $v) {
            self::assertIdent((string) $c);
            $set[] = "`{$c}` = ?";
            $params[] = $v;
        }
        $cond = [];
        foreach ($where as $c => $v) {
            self::assertIdent((string) $c);
            $cond[] = "`{$c}` = ?";
            $params[] = $v;
        }
        return self::run('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond), $params)->rowCount();
    }

    /**
     * Jalankan $fn dalam transaksi. Dapat bersarang (memakai SAVEPOINT).
     * Pengecualian apa pun → rollback lalu dilempar ulang.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $outer = self::$depth === 0;
        $sp = 'sp_' . self::$depth;
        if ($outer) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT {$sp}");
        }
        self::$depth++;
        try {
            $result = $fn();
            self::$depth--;
            if ($outer) {
                $pdo->commit();
            } else {
                $pdo->exec("RELEASE SAVEPOINT {$sp}");
            }
            return $result;
        } catch (Throwable $e) {
            self::$depth--;
            try {
                if ($outer) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                }
            } catch (Throwable $ignored) {
                // sambungan mungkin sudah putus; pengecualian asli yang lebih penting
            }
            throw $e;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /** Hanya untuk tes/migrator: lepas koneksi agar bisa berganti database. */
    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    /** Nama tabel/kolom tidak bisa di-bind; hanya izinkan identifier aman. */
    private static function assertIdent(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Identifier tidak valid.');
        }
    }
}
