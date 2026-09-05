<?php

namespace Tests\Unit;

use App\Services\License\CrockfordBase32;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CrockfordBase32Test extends TestCase
{
    public function test_alphabet_does_not_contain_excluded_characters(): void
    {
        $alphabet = CrockfordBase32::ALPHABET;

        $this->assertSame(32, strlen($alphabet));
        $this->assertStringNotContainsString('I', $alphabet);
        $this->assertStringNotContainsString('L', $alphabet);
        $this->assertStringNotContainsString('O', $alphabet);
        $this->assertStringNotContainsString('U', $alphabet);
    }

    public function test_encodes_raw_bytes_into_uppercase_crockford_base32(): void
    {
        $bytes = random_bytes(10); // 80 bits
        $encoded = CrockfordBase32::encodeBytes($bytes);

        $this->assertSame(16, strlen($encoded));
        $this->assertTrue(CrockfordBase32::isValid($encoded));
        $this->assertSame(strtoupper($encoded), $encoded);
    }

    public function test_normalizes_confusable_characters_and_lowercase(): void
    {
        $this->assertSame('1', CrockfordBase32::normalize('i'));
        $this->assertSame('1', CrockfordBase32::normalize('I'));
        $this->assertSame('1', CrockfordBase32::normalize('l'));
        $this->assertSame('1', CrockfordBase32::normalize('L'));
        $this->assertSame('0', CrockfordBase32::normalize('o'));
        $this->assertSame('0', CrockfordBase32::normalize('O'));
        $this->assertSame('ABC10', CrockfordBase32::normalize('a-b-c-I-O'));
    }

    public function test_encodes_and_decodes_20_bit_integers(): void
    {
        $values = [0, 1, 1024, 0xFFFFF, 543210];

        foreach ($values as $val) {
            $encoded = CrockfordBase32::encode20BitInt($val);
            $this->assertSame(4, strlen($encoded));
            $this->assertTrue(CrockfordBase32::isValid($encoded));

            $decoded = CrockfordBase32::decode20BitInt($encoded);
            $this->assertSame($val, $decoded);
        }
    }

    public function test_encode_20_bit_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CrockfordBase32::encode20BitInt(0x100000); // 21 bits
    }
}
