<?php
declare(strict_types=1);

/**
 * Infrastruktur tes end-to-end: menjalankan aplikasi asli di server PHP bawaan yang terhubung ke
 * database TES, lalu mengaksesnya dengan klien HTTP bercookie (mirip browser).
 */

final class TestServer
{
    /** @var resource|null */
    private static $proc = null;
    public static string $base = '';
    public static string $stderr = '';

    public static function start(string $logDir): void
    {
        if (self::$proc !== null) {
            return;
        }
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr((string) strrchr((string) $name, ':'), 1);

        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_NAME' => 'hme_peminjaman_test',
            // '/' (bukan '') karena variabel lingkungan kosong tidak diteruskan di Windows; Config menormalkannya jadi ''
            'APP_BASE_PATH' => '/', 'LOG_DIR' => $logDir, 'TRUST_PROXY' => 'false',
            'UPLOAD_DIR' => (string) getenv('UPLOAD_DIR'),
        ]);
        $root = dirname(__DIR__);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/tests/router.php'];
        $desc = [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/hme_test_server.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/hme_test_server.err', 'w']];
        self::$proc = proc_open($cmd, $desc, $pipes, $root, $env);
        if (!is_resource(self::$proc)) {
            throw new RuntimeException('Server uji gagal dijalankan.');
        }
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(100000);
        }
        self::stop();
        throw new RuntimeException('Server uji tidak merespons.');
    }

    public static function stop(): void
    {
        if (self::$proc !== null) {
            @proc_terminate(self::$proc);
            @proc_close(self::$proc);
            self::$proc = null;
        }
    }
}

/** Klien HTTP dengan cookie jar. */
final class Client
{
    /** @var array<string,string> */
    public array $cookies = [];
    public string $csrf = '';

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,mixed>|null $form
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,array<int,string>>,body:string,json:?array}
     */
    public function request(string $method, string $path, ?array $json = null, ?array $form = null, array $headers = [], ?array $multipart = null, ?string $raw = null): array
    {
        $ch = curl_init(TestServer::$base . $path);
        $h = ['Accept: ' . ($headers['Accept'] ?? '*/*')];
        unset($headers['Accept']);
        foreach ($headers as $k => $v) {
            $h[] = "{$k}: {$v}";
        }
        if ($this->cookies !== []) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $h,
        ];
        if ($raw !== null) {
            $opts[CURLOPT_POSTFIELDS] = $raw;           // badan mentah (multipart dirakit manual, tanpa menyentuh disk)
        } elseif ($multipart !== null) {
            $opts[CURLOPT_POSTFIELDS] = $multipart;   // array + CURLFile → multipart/form-data
        } elseif ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        } elseif ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        // Koneksi gagal dibuka (status 0, mis. port sementara Windows habis setelah ribuan request beruntun) → ulangi sekali.
        // Bukan untuk menutupi error aplikasi: respons HTTP apa pun (termasuk 5xx) tidak pernah diulang.
        // Hanya bila koneksi TCP tidak pernah terbentuk (request belum sampai ke server) → aman diulang.
        for ($try = 0; $try < 3 && $status === 0 && (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) === 0.0
                && in_array(curl_errno($ch), [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT], true); $try++) {
            usleep(500000);
            $raw = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
        }
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headBlock = substr($raw, 0, $size);
        $body = substr($raw, $size);
        $out = [];
        foreach (preg_split('/\r?\n/', $headBlock) ?: [] as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $out[strtolower(trim($k))][] = trim($v);
        }
        foreach ($out['set-cookie'] ?? [] as $sc) {
            $pair = explode(';', $sc)[0];
            [$n, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01 Jan 1970') !== false || stripos($sc, 'Max-Age=0') !== false) {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        // Seperti browser: halaman HTML membawa token CSRF terbaru (meta atau input tersembunyi)
        if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"|name="_csrf" value="([a-f0-9]{64})"/', $body, $m)) {
            $this->csrf = $m[2] ?? $m[1];
        }
        $json = json_decode($body, true);
        return ['status' => $status, 'headers' => $out, 'body' => $body, 'json' => is_array($json) ? $json : null, 'error' => $curlError];
    }

    /** @return array{status:int,headers:array<string,array<int,string>>,body:string,json:?array} */
    public function get(string $path, array $headers = []): array { return $this->request('GET', $path, null, null, $headers); }

    /** @param array<string,mixed> $body */
    public function api(string $method, string $path, ?array $body = null, ?string $token = null, array $headers = []): array
    {
        $headers['Accept'] = 'application/json';
        $t = $token ?? $this->csrf;
        if ($t !== '') {
            $headers['X-CSRF-Token'] = $t;
        }
        $r = $this->request($method, '/api/v1' . $path, $body ?? ($method === 'GET' ? null : []), null, $headers);
        if (isset($r['json']['data']['csrf_token'])) {
            $this->csrf = (string) $r['json']['data']['csrf_token'];
        }
        return $r;
    }

    /** Buka halaman login dan ambil token CSRF dari formulir (seperti browser). */
    public function bootstrapGuest(): string
    {
        $r = $this->get('/login');
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m)) {
            throw new AssertionFailed('token CSRF tidak ditemukan di halaman login');
        }
        return $this->csrf = $m[1];
    }

    /** Login lewat API; mengembalikan respons. */
    public function loginApi(string $username, string $password, string $role = 'staff'): array
    {
        if ($this->csrf === '') {
            $this->bootstrapGuest();
        }
        return $this->api('POST', '/auth/login', ['username' => $username, 'password' => $password, 'role' => $role]);
    }

    /**
     * POST multipart (formulir + berkas). $files: nama field → [path, mime, nama-berkas-kiriman].
     *
     * @param array<string,mixed> $fields
     * @param array<string,array{0:string,1:string,2:string}> $files
     */
    public function multipart(string $path, array $fields, array $files = [], ?string $token = null, array $headers = []): array
    {
        $headers['Accept'] = 'application/json';
        $t = $token ?? $this->csrf;
        if ($t !== '') {
            $headers['X-CSRF-Token'] = $t;
        }
        $parts = $fields;
        foreach ($files as $name => [$p, $mime, $filename]) {
            $parts[$name] = new CURLFile($p, $mime, $filename);
        }
        return $this->request('POST', '/api/v1' . $path, null, null, $headers, $parts);
    }

    /**
     * Seperti multipart() tetapi berkas dikirim langsung dari memori (tanpa berkas sementara).
     * Dipakai untuk muatan berbahaya agar antivirus tidak menghapusnya sebelum terkirim.
     *
     * @param array<string,mixed> $fields
     * @param array<string,array{0:string,1:string,2:string}> $files nama field → [byte, mime, nama-berkas]
     */
    public function multipartBytes(string $path, array $fields, array $files, ?string $token = null): array
    {
        $b = '----hme' . bin2hex(random_bytes(8));
        $nl = chr(13) . chr(10);   // multipart WAJIB CRLF
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= '--' . $b . $nl . 'Content-Disposition: form-data; name="' . $k . '"' . $nl . $nl . $v . $nl;
        }
        foreach ($files as $name => [$bytes, $mime, $filename]) {
            $body .= '--' . $b . $nl . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . $nl
                . 'Content-Type: ' . $mime . $nl . $nl . $bytes . $nl;
        }
        $body .= '--' . $b . '--' . $nl;
        $headers = ['Accept' => 'application/json', 'Content-Type' => "multipart/form-data; boundary={$b}"];
        $t = $token ?? $this->csrf;
        if ($t !== '') {
            $headers['X-CSRF-Token'] = $t;
        }
        return $this->request('POST', '/api/v1' . $path, null, null, $headers, null, $body);
    }

    public function cookie(string $name = 'HME_SESSION'): ?string { return $this->cookies[$name] ?? null; }
}
