<?php

namespace App\Services\License;

/**
 * Immutable Value Object representing a newly generated license key.
 */
readonly class GeneratedLicenseKey
{
    /**
     * @param string $plaintextKey Full formatted plaintext license key (transient only)
     * @param string $canonicalKey Canonical uppercase hyphenated license key
     * @param string $keyHash 64-character lowercase hexadecimal SHA-256 digest
     * @param string $keyMasked Masked key identifier for administrative display
     */
    public function __construct(
        public string $plaintextKey,
        public string $canonicalKey,
        public string $keyHash,
        public string $keyMasked
    ) {}
}
