<?php

namespace App\Services\License;

use InvalidArgumentException;

class LicenseKeyGenerator
{
    /**
     * Generate a new cryptographically random license key object.
     *
     * @param string $appCode 2-5 character alphanumeric application code
     * @return GeneratedLicenseKey
     *
     * @throws InvalidArgumentException
     */
    public function generate(string $appCode): GeneratedLicenseKey
    {
        $appCode = strtoupper(trim($appCode));
        if (! preg_match('/^[A-Z0-9]{2,5}$/', $appCode)) {
            throw new InvalidArgumentException("Invalid application code [{$appCode}]. Must be 2-5 uppercase alphanumeric characters.");
        }

        // 1. Generate 80 bits (10 bytes) of cryptographically secure random bytes
        $rawBytes = random_bytes(10);

        // 2. Encode to 16 Crockford Base32 characters
        $payload = CrockfordBase32::encodeBytes($rawBytes);
        if (strlen($payload) !== 16) {
            throw new InvalidArgumentException('Failed to generate 16-character payload from 80-bit entropy.');
        }

        // 3. Compute deterministic 4-character non-cryptographic checksum
        $checksum = LicenseKeyChecksum::compute($appCode, $payload);

        // 4. Format into human-readable 4-character segments
        $chunk1 = substr($payload, 0, 4);
        $chunk2 = substr($payload, 4, 4);
        $chunk3 = substr($payload, 8, 4);
        $chunk4 = substr($payload, 12, 4);

        $plaintextKey = "{$appCode}-{$chunk1}-{$chunk2}-{$chunk3}-{$chunk4}-{$checksum}";
        $canonicalKey = $plaintextKey;

        // 5. Compute SHA-256 digest encoded as lowercase hexadecimal
        $keyHash = hash('sha256', $canonicalKey);

        // 6. Compute masked key for administrative storage and display
        $keyMasked = "{$appCode}-****-****-****-****-{$checksum}";

        return new GeneratedLicenseKey(
            plaintextKey: $plaintextKey,
            canonicalKey: $canonicalKey,
            keyHash: $keyHash,
            keyMasked: $keyMasked
        );
    }

    /**
     * Parse and validate a license key string.
     *
     * @param string $inputKey
     * @return array{app_code: string, payload: string, checksum: string, canonical_key: string}|null
     */
    public function parse(string $inputKey): ?array
    {
        $parts = explode('-', strtoupper(trim($inputKey)));
        if (count($parts) !== 6) {
            return null;
        }

        $appCode = $parts[0];
        $chunk1 = CrockfordBase32::normalize($parts[1]);
        $chunk2 = CrockfordBase32::normalize($parts[2]);
        $chunk3 = CrockfordBase32::normalize($parts[3]);
        $chunk4 = CrockfordBase32::normalize($parts[4]);
        $checksum = CrockfordBase32::normalize($parts[5]);

        if (! preg_match('/^[A-Z0-9]{2,5}$/', $appCode)) {
            return null;
        }

        $payload = $chunk1 . $chunk2 . $chunk3 . $chunk4;
        if (strlen($payload) !== 16 || strlen($checksum) !== 4) {
            return null;
        }

        if (! CrockfordBase32::isValid($payload) || ! CrockfordBase32::isValid($checksum)) {
            return null;
        }

        if (! LicenseKeyChecksum::validate($appCode, $payload, $checksum)) {
            return null;
        }

        $canonicalKey = "{$appCode}-{$chunk1}-{$chunk2}-{$chunk3}-{$chunk4}-{$checksum}";

        return [
            'app_code' => $appCode,
            'payload' => $payload,
            'checksum' => $checksum,
            'canonical_key' => $canonicalKey,
        ];
    }
}
