<?php

namespace Tests\Unit;

use App\Services\License\LicenseKeyChecksum;
use PHPUnit\Framework\TestCase;

class LicenseKeyChecksumTest extends TestCase
{
    public function test_computes_deterministic_4_char_checksum(): void
    {
        $appCode = 'SPJ';
        $payload = '7B8NM4K92PQR8W5T';

        $checksum1 = LicenseKeyChecksum::compute($appCode, $payload);
        $checksum2 = LicenseKeyChecksum::compute($appCode, $payload);

        $this->assertSame(4, strlen($checksum1));
        $this->assertSame($checksum1, $checksum2);
        $this->assertTrue(LicenseKeyChecksum::validate($appCode, $payload, $checksum1));
    }

    public function test_rejects_tampered_payload(): void
    {
        $appCode = 'SPJ';
        $payload = '7B8NM4K92PQR8W5T';
        $checksum = LicenseKeyChecksum::compute($appCode, $payload);

        $tamperedPayload = '7B8NM4K92PQR8W5A'; // Last char altered

        $this->assertFalse(LicenseKeyChecksum::validate($appCode, $tamperedPayload, $checksum));
    }

    public function test_rejects_different_application_code(): void
    {
        $payload = '7B8NM4K92PQR8W5T';
        $checksum = LicenseKeyChecksum::compute('SPJ', $payload);

        $this->assertFalse(LicenseKeyChecksum::validate('BNY', $payload, $checksum));
    }

    public function test_rejects_invalid_checksum_length(): void
    {
        $this->assertFalse(LicenseKeyChecksum::validate('SPJ', '7B8NM4K92PQR8W5T', '123'));
        $this->assertFalse(LicenseKeyChecksum::validate('SPJ', '7B8NM4K92PQR8W5T', '12345'));
    }
}
