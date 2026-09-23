<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * Zustand der laufenden Anfrage, geteilt zwischen Middleware und View (Nonce, aktuelle Anfrage).
 */
final class RequestContext
{
    private string $nonce;

    private ?Request $request = null;

    public function __construct()
    {
        $this->nonce = self::generateNonce();
    }

    public function begin(Request $request): void
    {
        $this->request = $request;
        $this->nonce = self::generateNonce();
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    private static function generateNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
