<?php

namespace App\Services\License\Token;

use RuntimeException;

class Ed25519TokenVerifier implements TokenVerifierInterface
{
    public function __construct(
        private TrustedKeyRegistryInterface $keyRegistry
    ) {}

    /**
     * Cryptographically verify a compact signed license token against trusted public keys.
     *
     * @param string $token Compact 3-part token (header.payload.signature)
     * @return array<string, mixed> Returns verified decoded payload
     *
     * @throws RuntimeException If token format, header, kid, or cryptographic signature is invalid
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token structure. Expected 3 dot-separated segments.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        if (trim($headerB64) === '' || trim($payloadB64) === '' || trim($signatureB64) === '') {
            throw new RuntimeException('Malformed token structure. Segments cannot be empty.');
        }

        // 1. Decode and strictly validate header algorithm and type
        $headerJson = self::base64UrlDecode($headerB64);
        $header = json_decode($headerJson, true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'Ed25519' || ($header['typ'] ?? null) !== 'CLS-LIC-V1') {
            throw new RuntimeException('Unsupported token header algorithm or type.');
        }

        // 2. Resolve public key for kid from trusted key registry
        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '' || ! $this->keyRegistry->hasKey($kid)) {
            throw new RuntimeException("Unknown or unsupported key identifier (kid) [{$kid}].");
        }

        $publicKey = $this->keyRegistry->getPublicKey($kid);
        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException("Trusted public key material for kid [{$kid}] is invalid.");
        }

        // 3. Decode and validate signature bytes
        $signature = self::base64UrlDecode($signatureB64);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Invalid token signature length.');
        }

        // 4. Verify RFC 8032 detached signature over header . payload
        $dataToVerify = $headerB64 . '.' . $payloadB64;
        if (! sodium_crypto_sign_verify_detached($signature, $dataToVerify, $publicKey)) {
            throw new RuntimeException('Invalid token signature. Cryptographic verification failed.');
        }

        // 5. Decode payload JSON
        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            throw new RuntimeException('Malformed token payload JSON.');
        }

        return $payload;
    }

    /**
     * Safely parse the 3-part token structure and decode header/payload without trusting signature.
     *
     * @param string $token
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, raw_signature: string}
     */
    public function parseUnverified(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token structure. Expected 3 dot-separated segments.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        if (trim($headerB64) === '' || trim($payloadB64) === '' || trim($signatureB64) === '') {
            throw new RuntimeException('Malformed token structure. Segments cannot be empty.');
        }

        $headerJson = self::base64UrlDecode($headerB64);
        $header = json_decode($headerJson, true);
        if (! is_array($header)) {
            throw new RuntimeException('Malformed token header JSON.');
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            throw new RuntimeException('Malformed token payload JSON.');
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'raw_signature' => self::base64UrlDecode($signatureB64),
        ];
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padLen = 4 - $remainder;
            $data .= str_repeat('=', $padLen);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
