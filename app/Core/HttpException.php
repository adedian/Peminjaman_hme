<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Kesalahan yang aman ditampilkan ke pengguna (pesan sudah disaring). */
class HttpException extends RuntimeException
{
    private int $status;
    private string $errorCode;
    /** @var array<string,string> */
    private array $fields;
    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,string> $fields  pesan per field untuk 422
     * @param array<string,string> $headers header tambahan (mis. Allow, Retry-After)
     */
    public function __construct(int $status, string $errorCode, string $message, array $fields = [], array $headers = [])
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
        $this->headers = $headers;
    }

    public function status(): int { return $this->status; }
    public function errorCode(): string { return $this->errorCode; }
    /** @return array<string,string> */
    public function fields(): array { return $this->fields; }
    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }
}
