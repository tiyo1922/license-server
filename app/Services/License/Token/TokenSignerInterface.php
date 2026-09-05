<?php

namespace App\Services\License\Token;

interface TokenSignerInterface
{
    /**
     * Sign a license token payload and return the compact token string.
     *
     * @param array<string, mixed> $payload
     * @return string Compact base64url header.payload.signature
     */
    public function sign(array $payload): string;

    /**
     * Verify a compact signed token string against the public key.
     *
     * @param string $token
     * @return array<string, mixed> Decoded payload if valid
     *
     * @throws \RuntimeException If token is invalid or signature verification fails
     */
    public function verify(string $token): array;

    /**
     * Get the active Key Identifier (kid).
     */
    public function getKeyId(): string;
}
