<?php

namespace App\Services\License\Token;

use RuntimeException;

class Ed25519TokenSigner implements TokenSignerInterface
{
    private string $privateKey;
    private string $publicKey;
    private string $keyId;
    private bool $isEphemeral = false;
    private TokenVerifierInterface $verifier;

    public function __construct(
        ?string $privateKeyBase64 = null,
        ?string $publicKeyBase64 = null,
        ?string $keyId = null,
        ?TokenVerifierInterface $verifier = null
    ) {
        $hasConfig = function_exists('app') && app()->bound('config');
        $this->keyId = $keyId ?? ($hasConfig ? (string) config('license.ed25519_key_id', 'cls-ed25519-2026-v1') : 'cls-ed25519-2026-v1');

        $configuredPrivateKey = $privateKeyBase64 ?? ($hasConfig ? config('license.ed25519_private_key') : null);
        $configuredPublicKey = $publicKeyBase64 ?? ($hasConfig ? config('license.ed25519_public_key') : null);

        $isProduction = false;
        if (function_exists('app') && app()->bound('env')) {
            $isProduction = app()->environment('production');
        } elseif ($hasConfig) {
            $isProduction = config('app.env') === 'production';
        }

        if ($configuredPrivateKey) {
            $rawSecret = base64_decode($configuredPrivateKey, true);
            if ($rawSecret === false || (strlen($rawSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && strlen($rawSecret) !== SODIUM_CRYPTO_SIGN_SEEDBYTES)) {
                throw new RuntimeException('Configured ED25519_PRIVATE_KEY is invalid. Expected Base64 64-byte secret key or 32-byte seed.');
            }

            if (strlen($rawSecret) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                $keypair = sodium_crypto_sign_seed_keypair($rawSecret);
                $this->privateKey = sodium_crypto_sign_secretkey($keypair);
                $derivedPublicKey = sodium_crypto_sign_publickey($keypair);
            } else {
                $this->privateKey = $rawSecret;
                $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($rawSecret);
            }

            if ($configuredPublicKey) {
                $rawPub = base64_decode($configuredPublicKey, true);
                if ($rawPub === false || strlen($rawPub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    throw new RuntimeException('Configured ED25519_PUBLIC_KEY is invalid. Expected Base64 32-byte public key.');
                }
                if (! hash_equals($derivedPublicKey, $rawPub)) {
                    throw new RuntimeException('Configured ED25519_PUBLIC_KEY does not match the key derived from ED25519_PRIVATE_KEY.');
                }
                $this->publicKey = $rawPub;
            } else {
                $this->publicKey = $derivedPublicKey;
            }
        } elseif ($isProduction) {
            throw new RuntimeException('Production Ed25519 signing private key is unconfigured. Set ED25519_PRIVATE_KEY in production environment.');
        } elseif ($configuredPublicKey) {
            $rawPub = base64_decode($configuredPublicKey, true);
            if ($rawPub === false || strlen($rawPub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new RuntimeException('Configured ED25519_PUBLIC_KEY is invalid. Expected Base64 32-byte public key.');
            }
            $this->privateKey = '';
            $this->publicKey = $rawPub;
        } else {
            // Default ephemeral keypair for testing when no environment key is provisioned
            $keypair = sodium_crypto_sign_keypair();
            $this->privateKey = sodium_crypto_sign_secretkey($keypair);
            $this->publicKey = sodium_crypto_sign_publickey($keypair);
            $this->isEphemeral = true;
        }

        if ($verifier !== null) {
            $this->verifier = $verifier;
        } else {
            $registry = new StaticTrustedKeyRegistry([
                $this->keyId => $this->publicKey,
            ]);
            $this->verifier = new Ed25519TokenVerifier($registry);
        }
    }

    /**
     * Sign a license token payload and return the compact token string.
     */
    public function sign(array $payload): string
    {
        if ($this->privateKey === '') {
            throw new RuntimeException('Private signing key is not available on this instance.');
        }

        $header = [
            'alg' => 'Ed25519',
            'typ' => 'CLS-LIC-V1',
            'kid' => $this->keyId,
        ];

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headerB64 = Ed25519TokenVerifier::base64UrlEncode($headerJson);
        $payloadB64 = Ed25519TokenVerifier::base64UrlEncode($payloadJson);

        $dataToSign = $headerB64 . '.' . $payloadB64;
        $signature = sodium_crypto_sign_detached($dataToSign, $this->privateKey);

        $signatureB64 = Ed25519TokenVerifier::base64UrlEncode($signature);

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    /**
     * Verify a compact signed token string against the public key via TokenVerifier.
     */
    public function verify(string $token): array
    {
        return $this->verifier->verify($token);
    }

    public function isEphemeral(): bool
    {
        return $this->isEphemeral;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getPublicKeyBase64(): string
    {
        return base64_encode($this->publicKey);
    }

    public static function base64UrlEncode(string $data): string
    {
        return Ed25519TokenVerifier::base64UrlEncode($data);
    }

    public static function base64UrlDecode(string $data): string
    {
        return Ed25519TokenVerifier::base64UrlDecode($data);
    }
}
