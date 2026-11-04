<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $status;
    private string $body;
    /** @var array<string,string> */
    private array $headers;

    /** @param array<string,string> $headers */
    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /** @param mixed $data */
    public static function json($data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return new self($body === false ? '{"ok":false}' : $body, $status, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param mixed $data */
    public static function ok($data = null, int $status = 200): self
    {
        return self::json(['ok' => true, 'data' => $data], $status);
    }

    /** @param array<string,string> $fields */
    public static function error(int $status, string $code, string $message, array $fields = []): self
    {
        $err = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $err['fields'] = $fields;
        }
        return self::json(['ok' => false, 'error' => $err], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    public static function noContent(): self
    {
        return new self('', 204, ['Cache-Control' => 'no-store']);
    }

    public function withHeader(string $name, string $value): self
    {
        $c = clone $this;
        $c->headers[$name] = $value;
        return $c;
    }

    public function status(): int { return $this->status; }
    public function body(): string { return $this->body; }
    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }
    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** Header keamanan standar untuk setiap respons dari aplikasi. */
    public function withSecurityHeaders(?Request $request = null): self
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' https://fonts.googleapis.com",
            "style-src-attr 'unsafe-inline'",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        $defaults = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'camera=(self), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => $csp,
        ];
        if ($request !== null && $request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=15552000; includeSubDomains';
        }
        $c = clone $this;
        foreach ($defaults as $k => $v) {
            if ($c->header($k) === null) {
                $c->headers[$k] = $v;
            }
        }
        return $c;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . str_replace(["\r", "\n"], '', $v));
            }
        }
        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
