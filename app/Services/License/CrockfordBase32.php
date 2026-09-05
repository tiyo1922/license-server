<?php

namespace App\Services\License;

use InvalidArgumentException;

class CrockfordBase32
{
    /**
     * Canonical Crockford Base32 alphabet (32 symbols).
     * Excluded: I, L, O, U.
     */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Encode raw binary bytes into Crockford Base32 string.
     *
     * @param string $bytes Raw binary string
     * @return string Uppercase Crockford Base32 encoded string
     *
     * @throws InvalidArgumentException
     */
    public static function encodeBytes(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len === 0) {
            return '';
        }

        // Convert binary string to bit stream
        $bits = '';
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Pad with zeros to a multiple of 5 bits if necessary
        $remainder = strlen($bits) % 5;
        if ($remainder !== 0) {
            $bits .= str_repeat('0', 5 - $remainder);
        }

        $encoded = '';
        $totalChunks = strlen($bits) / 5;
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunk = substr($bits, $i * 5, 5);
            $index = bindec($chunk);
            $encoded .= self::ALPHABET[$index];
        }

        return $encoded;
    }

    /**
     * Encode a 20-bit integer into exactly 4 Crockford Base32 characters.
     *
     * @param int $value 20-bit integer (0 to 1,048,575)
     * @return string 4-character uppercase Crockford Base32 string
     *
     * @throws InvalidArgumentException
     */
    public static function encode20BitInt(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFF) {
            throw new InvalidArgumentException('Value must be an unsigned 20-bit integer.');
        }

        return self::ALPHABET[($value >> 15) & 0x1F]
            . self::ALPHABET[($value >> 10) & 0x1F]
            . self::ALPHABET[($value >> 5) & 0x1F]
            . self::ALPHABET[$value & 0x1F];
    }

    /**
     * Decode a 4-character Crockford Base32 checksum string into a 20-bit integer.
     *
     * @param string $encoded 4-character string
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public static function decode20BitInt(string $encoded): int
    {
        $normalized = self::normalize($encoded);
        if (strlen($normalized) !== 4) {
            throw new InvalidArgumentException('Encoded checksum must be exactly 4 characters.');
        }

        $value = 0;
        for ($i = 0; $i < 4; $i++) {
            $char = $normalized[$i];
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new InvalidArgumentException("Invalid Crockford Base32 character [{$char}].");
            }
            $value = ($value << 5) | $pos;
        }

        return $value;
    }

    /**
     * Normalize user input string:
     * - Convert to uppercase
     * - Replace i/I/l/L with 1
     * - Replace o/O with 0
     * - Strip whitespace and hyphens
     *
     * @param string $input
     * @return string
     */
    public static function normalize(string $input): string
    {
        $cleaned = strtoupper(trim($input));
        $cleaned = str_replace(['-', ' '], '', $cleaned);
        $cleaned = str_replace(['I', 'L'], '1', $cleaned);
        $cleaned = str_replace('O', '0', $cleaned);

        return $cleaned;
    }

    /**
     * Check if a string consists strictly of valid Crockford Base32 characters.
     *
     * @param string $string
     * @return bool
     */
    public static function isValid(string $string): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]+$/', $string) === 1;
    }
}
