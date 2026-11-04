<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> header (nama huruf kecil) */
    private array $headers;
    /** @var array<string,mixed> */
    private array $server;
    /** @var array<string,mixed> data yang ditempel middleware (mis. user) */
    private array $attributes = [];
    /** @var array<string,mixed> salinan $_FILES */
    private array $files = [];
    private string $method;
    private string $path;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,mixed> $server
     */
    public function __construct(string $method, string $path, array $query = [], array $body = [], array $headers = [], array $server = [], array $files = [])
    {
        $this->files   = $files;
        $this->method  = strtoupper($method);
        $this->path    = $path === '' ? '/' : $path;
        $this->query   = $query;
        $this->body    = $body;
        $this->server  = $server;
        $this->headers = [];
        foreach ($headers as $k => $v) {
            $this->headers[strtolower($k)] = $v;
        }
    }

    public static function fromGlobals(): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rawurldecode($path);
        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && strncmp($path, $base, strlen($base)) === 0) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strncmp($k, 'HTTP_', 5) === 0) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $sk => $hk) {
            if (isset($_SERVER[$sk])) {
                $headers[$hk] = (string) $_SERVER[$sk];
            }
        }

        $body = $_POST;
        $ctype = strtolower($headers['content-type'] ?? '');
        if (strpos($ctype, 'application/json') === 0) {
            $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1) ?: '';
            if (strlen($raw) > 1024 * 1024) {
                throw new HttpException(413, 'payload_too_large', 'Data yang dikirim terlalu besar.');
            }
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'invalid_json', 'Format JSON tidak valid.');
                }
                $body = $decoded;
            }
        }
        // POST lebih besar dari post_max_size: PHP mengosongkan $_POST dan $_FILES tanpa pesan apa pun.
        if (empty($_POST) && empty($_FILES) && (int) ($headers['content-length'] ?? 0) > 0 && strpos($ctype, 'multipart/form-data') === 0) {
            throw new HttpException(413, 'payload_too_large', 'Data yang dikirim melebihi batas ukuran server.');
        }
        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $body, $headers, $_SERVER, $_FILES);
    }

    /**
     * Satu berkas unggahan (bentuk $_FILES), atau null bila tidak ada / tidak dipilih.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            return null; // tidak ada, atau penyerang mengirim berkas bertingkat (photo[]=...)
        }
        if ((int) $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function isApi(): bool { return strncmp($this->path, '/api/', 5) === 0; }
    public function isSafeMethod(): bool { return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true); }

    /** @return mixed */
    public function query(string $key, $default = null) { return $this->query[$key] ?? $default; }
    /** @return mixed */
    public function input(string $key, $default = null) { return $this->body[$key] ?? $default; }
    /** @return array<string,mixed> */
    public function body(): array { return $this->body; }
    public function header(string $name, ?string $default = null): ?string { return $this->headers[strtolower($name)] ?? $default; }

    public function ip(): string
    {
        if (Config::get('app.trust_proxy') && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $this->server['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string { return substr($this->header('user-agent', '') ?? '', 0, 255); }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return (bool) Config::get('app.trust_proxy') && strtolower($this->header('x-forwarded-proto', '') ?? '') === 'https';
    }

    public function host(): string { return strtolower((string) ($this->header('host') ?? ($this->server['HTTP_HOST'] ?? ''))); }

    /** @param mixed $value */
    public function setAttribute(string $key, $value): void { $this->attributes[$key] = $value; }
    /** @return mixed */
    public function attribute(string $key, $default = null) { return $this->attributes[$key] ?? $default; }

    /** @return array<string,mixed>|null baris user dari Auth middleware */
    public function user(): ?array
    {
        $u = $this->attributes['user'] ?? null;
        return is_array($u) ? $u : null;
    }
}
