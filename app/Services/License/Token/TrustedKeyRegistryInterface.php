<?php

namespace App\Services\License\Token;

interface TrustedKeyRegistryInterface
{
    /**
     * Retrieve the 32-byte raw binary Ed25519 public key for a given key ID (kid).
     *
     * @param string $kid Key Identifier
     * @return string|null 32-byte binary public key or null if kid is unknown/untrusted
     */
    public function getPublicKey(string $kid): ?string;

    /**
     * Check if a key ID is recognized and trusted.
     *
     * @param string $kid Key Identifier
     * @return bool
     */
    public function hasKey(string $kid): bool;

    /**
     * Get all registered key identifiers.
     *
     * @return list<string>
     */
    public function getAllKeyIds(): array;
}
