<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validasi input di sisi server (satu-satunya yang dipercaya; validasi JS hanya kenyamanan).
 *
 * Contoh:
 *   $v = Validator::make($req->body(), [
 *       'username' => 'required|string|min:3|max:50|alpha_dash',
 *       'role'     => 'required|in:admin,staff',
 *       'days'     => 'required|int|min:1',
 *       'notes'    => 'nullable|string|max:500',
 *   ], ['username' => 'Username']);
 *   $data = $v->validate();   // melempar HttpException 422 bila gagal
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,string> */
    private array $rules;
    /** @var array<string,string> */
    private array $labels;
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    private function __construct(array $data, array $rules, array $labels)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->labels = $labels;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    public function passes(): bool { return $this->errors === []; }
    /** @return array<string,string> */
    public function errors(): array { return $this->errors; }

    /** @return array<string,mixed> hanya field yang tercantum di rules, sudah dinormalisasi */
    public function validated(): array { return $this->clean; }

    /** @return array<string,mixed> */
    public function validate(): array
    {
        if (!$this->passes()) {
            throw new HttpException(422, 'validation_failed', 'Data yang dikirim belum valid.', $this->errors);
        }
        return $this->clean;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleStr) {
            $rules = $ruleStr === '' ? [] : explode('|', $ruleStr);
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $this->errors[$field] = "{$label} wajib diisi.";
                } else {
                    $this->clean[$field] = null;
                }
                continue;
            }
            if ($nullable && $empty) {
                $this->clean[$field] = null;
                continue;
            }

            $isNumeric = in_array('int', $rules, true) || in_array('numeric', $rules, true);
            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                $arg = null;
                if (strpos($rule, ':') !== false) {
                    [$rule, $arg] = explode(':', $rule, 2);
                }
                $err = $this->check($rule, $arg, $value, $label, $isNumeric);
                if ($err !== null) {
                    $this->errors[$field] = $err;
                    break;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->clean[$field] = $this->cast($rules, $value);
            }
        }
    }

    /** @param mixed $value */
    private function check(string $rule, ?string $arg, $value, string $label, bool $isNumeric): ?string
    {
        // Pengacauan tipe (mis. {"role":["admin"]}): nilai array hanya sah untuk aturan 'array'.
        // Tanpa ini, konversi array→string memicu error 500 alih-alih penolakan 422.
        if (is_array($value) && $rule !== 'array') {
            return $rule === 'string' ? "{$label} harus berupa teks." : "{$label} tidak valid.";
        }
        switch ($rule) {
            case 'string':
                return is_string($value) ? null : "{$label} harus berupa teks.";
            case 'int':
                return (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value))) ? null : "{$label} harus berupa bilangan bulat.";
            case 'numeric':
                return is_numeric($value) ? null : "{$label} harus berupa angka.";
            case 'boolean':
                return in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true) ? null : "{$label} harus benar atau salah.";
            case 'array':
                return is_array($value) ? null : "{$label} harus berupa daftar.";
            case 'email':
                return (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? null : "{$label} bukan alamat email yang valid.";
            case 'date':
                // Pola diperiksa dulu: di PHP 8 createFromFormat melempar ValueError untuk byte NUL (→ 500)
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                    return "{$label} harus berformat TTTT-BB-HH.";
                }
                $d = \DateTime::createFromFormat('!Y-m-d', $value);
                return ($d && $d->format('Y-m-d') === $value) ? null : "{$label} harus berformat TTTT-BB-HH.";
            case 'alpha_dash':
                return (is_string($value) && preg_match('/^[A-Za-z0-9._-]+$/', $value)) ? null : "{$label} hanya boleh berisi huruf, angka, titik, strip, dan garis bawah.";
            case 'in':
                return in_array((string) $value, explode(',', (string) $arg), true) ? null : "{$label} tidak valid.";
            case 'regex':
                return (is_string($value) && @preg_match((string) $arg, $value) === 1) ? null : "{$label} formatnya tidak sesuai.";
            case 'same':
                return ((string) ($this->data[(string) $arg] ?? '') === (string) $value) ? null : "{$label} tidak cocok.";
            case 'min':
                return $this->size($value, $isNumeric) >= (float) $arg ? null : ($isNumeric ? "{$label} minimal {$arg}." : "{$label} minimal {$arg} karakter.");
            case 'max':
                return $this->size($value, $isNumeric) <= (float) $arg ? null : ($isNumeric ? "{$label} maksimal {$arg}." : "{$label} maksimal {$arg} karakter.");
        }
        throw new \LogicException("Aturan validasi tidak dikenal: {$rule}");
    }

    /** @param mixed $value */
    private function size($value, bool $isNumeric): float
    {
        if ($isNumeric) {
            return (float) $value;
        }
        if (is_array($value)) {
            return (float) count($value);
        }
        return (float) mb_strlen((string) $value);
    }

    /** @param array<int,string> $rules @param mixed $value @return mixed */
    private function cast(array $rules, $value)
    {
        if (in_array('int', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return $value + 0;
        }
        if (in_array('boolean', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }
        return $value;
    }
}
