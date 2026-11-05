<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Web Push (RFC 8030) dengan enkripsi isi pesan RFC 8291 (aes128gcm) dan autentikasi server aplikasi VAPID RFC 8292,
 * hanya memakai ekstensi `openssl` + `curl` bawaan PHP (tanpa pustaka tambahan).
 *
 * Kebenaran enkripsi dibuktikan dengan vektor uji resmi RFC 8291 §5/Lampiran A (tests/cases/revisi_v2_push.php) dan
 * dekripsi silang oleh implementasi pihak ketiga (lihat docs/16).
 */
final class WebPush
{
    /** Header SubjectPublicKeyInfo P-256 (sebelum 65 byte titik tak-terkompresi). */
    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    /** Awalan ECPrivateKey P-256 → sisipkan 32 byte d, lalu bagian [0] OID & [1] kunci publik. */
    private const EC_PRIV_PREFIX = '30770201010420';
    private const EC_PRIV_MID = 'a00a06082a8648ce3d030107a144034200';

    /** @var (callable(string,array<int,string>,string,int):array{0:int,1:string,2:array<string,string>})|null */
    private static $transport = null;

    /** @param (callable(string,array<int,string>,string,int):array{0:int,1:string,2:array<string,string>})|null $t */
    public static function setTransport(?callable $t): void
    {
        self::$transport = $t;
    }

    // ----------------------------------------------------------------------------- kode dasar

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) {
            throw new RuntimeException('Base64url tidak valid.');
        }
        return $r;
    }

    /** Kunci VAPID baru. @return array{public:string,private:string} base64url (publik 65 byte, privat 32 byte) */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('Gagal membuat kunci EC: ' . (string) openssl_error_string() . ' (periksa konfigurasi OpenSSL PHP).');
        }
        $d = openssl_pkey_get_details($key);
        $ec = $d['ec'] ?? null;
        if (!is_array($ec)) {
            throw new RuntimeException('Detail kunci EC tidak tersedia.');
        }
        return [
            'public'  => self::b64uEncode("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            'private' => self::b64uEncode(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /** Kunci privat P-256 (OpenSSL) dari d mentah + titik publik mentah. @return \OpenSSLAsymmetricKey */
    private static function privateKey(string $d32, string $pub65)
    {
        $der = hex2bin(self::EC_PRIV_PREFIX) . $d32 . hex2bin(self::EC_PRIV_MID) . $pub65;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $k = openssl_pkey_get_private($pem);
        if ($k === false) {
            throw new RuntimeException('Kunci privat tidak valid.');
        }
        return $k;
    }

    /** @return \OpenSSLAsymmetricKey */
    private static function publicKey(string $pub65)
    {
        if (strlen($pub65) !== 65 || $pub65[0] !== "\x04") {
            throw new RuntimeException('Kunci publik harus 65 byte tak-terkompresi.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $pub65), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new RuntimeException('Kunci publik penerima tidak valid.');
        }
        return $k;
    }

    // ----------------------------------------------------------------------------- RFC 8291

    /**
     * Enkripsi isi pesan untuk satu langganan. $asPrivate/$asPublic/$salt hanya diisi oleh tes (vektor RFC);
     * produksi selalu membuat kunci sementara & salt acak baru.
     *
     * @return string badan HTTP (header aes128gcm + ciphertext)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPrivate = null, ?string $asPublic = null, ?string $salt = null, int $recordSize = 4096): string
    {
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Auth secret harus 16 byte.');
        }
        if (strlen($payload) > 3993) {   // batas 4096 byte per catatan - header - padding - tag
            throw new RuntimeException('Isi pesan terlalu besar.');
        }
        if ($asPrivate === null) {
            $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($eph === false) {
                throw new RuntimeException('Gagal membuat kunci sementara.');
            }
            $ec = openssl_pkey_get_details($eph)['ec'];
            $asPublic = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
            $ephKey = $eph;
        } else {
            $ephKey = self::privateKey($asPrivate, (string) $asPublic);
        }
        $salt = $salt ?? random_bytes(16);

        $shared = openssl_pkey_derive(self::publicKey($uaPublic), $ephKey, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH gagal: ' . (string) openssl_error_string());
        }
        $keyInfo = "WebPush: info\0" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Enkripsi AES-GCM gagal.');
        }
        return $salt . pack('N', $recordSize) . chr(strlen((string) $asPublic)) . $asPublic . $cipher . $tag;
    }

    // ----------------------------------------------------------------------------- RFC 8292

    /** JWT ES256 untuk VAPID. @return string "header.claims.signature" */
    public static function vapidJwt(string $audience, string $subject, string $privateB64u, string $publicB64u, ?int $expiresAt = null): string
    {
        $h = self::b64uEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64uEncode((string) json_encode(['aud' => $audience, 'exp' => $expiresAt ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $key = self::privateKey(self::b64uDecode($privateB64u), self::b64uDecode($publicB64u));
        $sig = '';
        if (!openssl_sign($h . '.' . $c, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Penandatanganan VAPID gagal.');
        }
        return $h . '.' . $c . '.' . self::b64uEncode(self::derToRaw($sig));
    }

    /** Verifikasi JWT VAPID (dipakai tes & pemeriksaan konfigurasi). */
    public static function verifyJwt(string $jwt, string $publicB64u): bool
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) {
            return false;
        }
        $pub = self::publicKey(self::b64uDecode($publicB64u));
        return openssl_verify($p[0] . '.' . $p[1], self::rawToDer(self::b64uDecode($p[2])), $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /** DER ECDSA → R||S 64 byte. */
    private static function derToRaw(string $der): string
    {
        $o = 2;
        if (ord($der[1]) & 0x80) {
            $o += ord($der[1]) & 0x7F;
        }
        $rl = ord($der[$o + 1]);
        $r = substr($der, $o + 2, $rl);
        $o2 = $o + 2 + $rl;
        $sl = ord($der[$o2 + 1]);
        $s = substr($der, $o2 + 2, $sl);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    private static function rawToDer(string $raw): string
    {
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || (ord($v[0]) & 0x80)) {
                $v = "\0" . $v;
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($body)) . $body;
    }

    // ----------------------------------------------------------------------------- pengiriman

    public static function configured(): bool
    {
        return (string) Config::get('push.vapid_public', '') !== '' && (string) Config::get('push.vapid_private', '') !== '';
    }

    /**
     * Kirim satu pesan.
     *
     * @param array{endpoint:string,p256dh:string,auth:string} $sub kunci dalam base64url (format PushSubscription.toJSON)
     * @return array{status:int,body:string,headers:array<string,string>} status 0 = gagal jaringan
     */
    public static function send(array $sub, string $payloadJson, int $ttl = 86400, string $urgency = 'normal', ?string $topic = null): array
    {
        $pub = (string) Config::get('push.vapid_public');
        $priv = (string) Config::get('push.vapid_private');
        $subject = (string) Config::get('push.subject', 'mailto:admin@example.com');
        $parts = parse_url($sub['endpoint']);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $jwt = self::vapidJwt($aud, $subject, $priv, $pub);
        $body = self::encrypt($payloadJson, self::b64uDecode($sub['p256dh']), self::b64uDecode($sub['auth']));
        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $pub,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($body),
            'TTL: ' . max(0, $ttl),
            'Urgency: ' . $urgency,
        ];
        if ($topic !== null && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $topic)) {
            $headers[] = 'Topic: ' . $topic;
        }
        $timeout = (int) Config::get('push.timeout', 15);
        if (self::$transport !== null) {
            [$status, $resp, $rh] = (self::$transport)($sub['endpoint'], $headers, $body, $timeout);
            return ['status' => $status, 'body' => $resp, 'headers' => $rh];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => 'Ekstensi cURL tidak tersedia.', 'headers' => []];
        }
        $respHeaders = [];
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,    // endpoint tidak boleh mengalihkan ke tempat lain (SSRF)
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $out === false ? 0 : $status, 'body' => $out === false ? 'Jaringan: ' . $err : (string) $out, 'headers' => $respHeaders];
    }
}
