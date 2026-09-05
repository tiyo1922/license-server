<?php

namespace App\Services\License\Token;

interface TokenVerifierInterface
{
    /**
     * Cryptographically verify a compact signed license token against trusted public keys.
     *
     * @param string $token Compact 3-part token (header.payload.signature)
     * @return array<string, mixed> Returns verified decoded payload
     *
     * @throws \RuntimeException If token format, header, kid, or cryptographic signature is invalid
     */
    public function verify(string $token): array;

    /**
     * Safely parse the 3-part token structure and decode header/payload without trusting signature.
     *
     * @param string $token
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, raw_signature: string}
     *
     * @throws \RuntimeException If token structure or Base64URL encoding is malformed
     */
    public function parseUnverified(string $token): array;
}
