<?php

namespace App\Services\License;

/**
 * Non-Cryptographic Checksum Service for License Keys.
 *
 * Purpose:
 * - Detects transcription typos and character transpositions.
 * - Rejects malformed or incorrectly formatted keys before database query execution.
 * - Non-cryptographic: Does NOT provide cryptographic secrecy or tamper-proofing.
 * - Computational complexity: O(n) relative to input string length.
 */
class LicenseKeyChecksum
{
    /**
     * Compute a deterministic 4-character Crockford Base32 checksum.
     *
     * @param string $appCode Uppercase application code (e.g. 'SPJ')
     * @param string $payload 16-character Crockford Base32 payload string
     * @return string 4-character uppercase Crockford Base32 checksum
     */
    public static function compute(string $appCode, string $payload): string
    {
        $appCode = strtoupper(trim($appCode));
        $payload = CrockfordBase32::normalize($payload);

        // Compute CRC32b over "APP_CODE:PAYLOAD"
        $canonicalData = $appCode . ':' . $payload;
        $crc = crc32($canonicalData);

        // Mask to 20 bits (0 to 1,048,575)
        $checksumInt = $crc & 0xFFFFF;

        return CrockfordBase32::encode20BitInt($checksumInt);
    }

    /**
     * Validate whether a provided checksum matches the computed checksum for the payload.
     * Complexity is O(n) relative to input string length.
     *
     * @param string $appCode Application code
     * @param string $payload 16-character payload string
     * @param string $checksum 4-character checksum string to verify
     * @return bool
     */
    public static function validate(string $appCode, string $payload, string $checksum): bool
    {
        $normalizedChecksum = CrockfordBase32::normalize($checksum);
        if (strlen($normalizedChecksum) !== 4) {
            return false;
        }

        $expectedChecksum = self::compute($appCode, $payload);

        return $normalizedChecksum === $expectedChecksum;
    }
}
