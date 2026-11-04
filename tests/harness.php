<?php
declare(strict_types=1);

/** Harness tes minimal tanpa dependensi (PHP 8.0). */

class AssertionFailed extends Exception {}

$GLOBALS['__tests'] = [];
$GLOBALS['__section'] = '';

function section(string $title): void { $GLOBALS['__section'] = $title; }

function t(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__section'], $name, $fn];
}

/** @param mixed $cond */
function ok($cond, string $msg = 'kondisi seharusnya benar'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

/** @param mixed $expected @param mixed $actual */
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . 'diharapkan ' . var_export($expected, true) . ', didapat ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . "'{$needle}' tidak ditemukan");
    }
}

function lacks(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new AssertionFailed(($msg !== '' ? $msg . ': ' : '') . "'{$needle}' seharusnya tidak ada");
    }
}

/** Menjalankan $fn dan mengembalikan exception yang dilempar; gagal bila tidak ada / kelas salah. */
function throws(callable $fn, string $class = Throwable::class): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new AssertionFailed("exception {$class} diharapkan, didapat " . get_class($e) . ': ' . $e->getMessage());
        }
        return $e;
    }
    throw new AssertionFailed("exception {$class} diharapkan tetapi tidak ada yang dilempar");
}

/** Kode error MySQL dari pernyataan yang gagal, atau null bila berhasil. */
function dbErr(callable $fn): ?int
{
    try {
        $fn();
        return null;
    } catch (PDOException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }
}

function out(string $s): void { fwrite(STDOUT, $s); }

function run_all(?string $filter): int
{
    $pass = 0;
    $fail = 0;
    $failures = [];
    $last = null;
    foreach ($GLOBALS['__tests'] as [$section, $name, $fn]) {
        if ($filter !== null && stripos($section . ' ' . $name, $filter) === false) {
            continue;
        }
        if ($section !== $last) {
            out("\n\033[1m" . $section . "\033[0m\n");
            $last = $section;
        }
        try {
            $fn();
            $pass++;
            out("  \033[32m✔\033[0m {$name}\n");
        } catch (Throwable $e) {
            $fail++;
            $failures[] = [$section, $name, $e];
            out("  \033[31m✘ {$name}\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "\n");
        }
    }
    out("\n" . str_repeat('=', 70) . "\n");
    out(($fail === 0 ? "\033[32m" : "\033[31m") . "{$pass} lulus, {$fail} gagal\033[0m\n");
    return $fail === 0 ? 0 : 1;
}
