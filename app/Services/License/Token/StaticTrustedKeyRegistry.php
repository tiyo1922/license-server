<?php

namespace App\Services\License\Token;

use InvalidArgumentException;

class StaticTrustedKeyRegistry implements TrustedKeyRegistryInterface
{
    /**
     * @var array<string, string> Map of kid to 32-byte raw binary public key
     */
    private array $keys = [];

    /**
     * @param array<string, string|null> $initialKeys Map of kid => base64_encoded_public_key or raw binary public key
     */
    public function __construct(array $initialKeys = [])
    {
        foreach ($initialKeys as $kid => $keyMaterial) {
            if ($keyMaterial !== null && trim($keyMaterial) !== '') {
                $this->registerKey((string) $kid, $keyMaterial);
            }
        }
    }

    /**
     * Register or update a trusted public key for a given kid.
     *
     * @param string $kid Key Identifier
     * @param string $publicKeyBase64OrBinary Base64-encoded or raw 32-byte binary public key
     * @return self
     *
     * @throws InvalidArgumentException If public key format or byte length is invalid
     */
    public function registerKey(string $kid, string $publicKeyBase64OrBinary): self
    {
        $kid = trim($kid);
        if ($kid === '') {
            throw new InvalidArgumentException('Key identifier (kid) cannot be empty.');
        }

        // Determine if raw 32-byte binary or base64 encoded
        if (strlen($publicKeyBase64OrBinary) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $rawBinary = $publicKeyBase64OrBinary;
        } else {
            $decoded = base64_decode($publicKeyBase64OrBinary, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException("Invalid Ed25519 public key for kid [{$kid}]. Expected 32 bytes.");
            }
            $rawBinary = $decoded;
        }

        $this->keys[$kid] = $rawBinary;

        return $this;
    }

    /**
     * Remove a key identifier from the registry.
     */
    public function removeKey(string $kid): self
    {
        unset($this->keys[trim($kid)]);
        return $this;
    }

    public function getPublicKey(string $kid): ?string
    {
        return $this->keys[trim($kid)] ?? null;
    }

    public function hasKey(string $kid): bool
    {
        return isset($this->keys[trim($kid)]);
    }

    public function getAllKeyIds(): array
    {
        return array_keys($this->keys);
    }
}
