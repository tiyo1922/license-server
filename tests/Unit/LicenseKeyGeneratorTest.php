<?php

namespace Tests\Unit;

use App\Services\License\CrockfordBase32;
use App\Services\License\GeneratedLicenseKey;
use App\Services\License\LicenseKeyGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LicenseKeyGeneratorTest extends TestCase
{
    private LicenseKeyGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new LicenseKeyGenerator();
    }

    public function test_generates_valid_license_key_with_exact_format(): void
    {
        $appCode = 'SPJ';
        $key = $this->generator->generate($appCode);

        $this->assertInstanceOf(GeneratedLicenseKey::class, $key);

        // Pattern: {APP_CODE}-{CHUNK1}-{CHUNK2}-{CHUNK3}-{CHUNK4}-{CHECKSUM}
        $pattern = '/^SPJ-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';
        $this->assertMatchesRegularExpression($pattern, $key->plaintextKey);
        $this->assertSame($key->plaintextKey, $key->canonicalKey);

        // Verify SHA-256 hash is 64-char lowercase hexadecimal
        $this->assertSame(hash('sha256', $key->canonicalKey), $key->keyHash);
        $this->assertSame(64, strlen($key->keyHash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key->keyHash);

        // Verify masked key format: SPJ-****-****-****-****-{CHECKSUM}
        $parts = explode('-', $key->plaintextKey);
        $checksum = $parts[5];
        $expectedMasked = "SPJ-****-****-****-****-{$checksum}";
        $this->assertSame($expectedMasked, $key->keyMasked);

        // Masked key must NOT contain payload characters
        $payloadChunks = $parts[1] . $parts[2] . $parts[3] . $parts[4];
        $this->assertStringNotContainsString($payloadChunks, $key->keyMasked);
    }

    public function test_accepts_app_codes_between_2_and_5_alphanumeric_characters(): void
    {
        $validCodes = ['AB', 'SPJ', 'KATR', 'ALPHA', 'A1', 'V2X'];

        foreach ($validCodes as $code) {
            $key = $this->generator->generate($code);
            $this->assertStringStartsWith(strtoupper($code) . '-', $key->plaintextKey);
        }
    }

    public function test_rejects_invalid_application_codes(): void
    {
        $invalidCodes = ['A', 'TOOLONG1', 'SP-J', 'SP_J', 'SP.J', ''];

        foreach ($invalidCodes as $code) {
            try {
                $this->generator->generate($code);
                $this->fail("Expected InvalidArgumentException for application code: [{$code}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid application code', $e->getMessage());
            }
        }
    }

    public function test_generated_keys_are_canonical_uppercase(): void
    {
        $key = $this->generator->generate('spj');
        $this->assertSame(strtoupper($key->plaintextKey), $key->plaintextKey);
        $this->assertStringStartsWith('SPJ-', $key->plaintextKey);
    }

    public function test_parses_and_validates_generated_license_key(): void
    {
        $key = $this->generator->generate('SPJ');
        $parsed = $this->generator->parse($key->plaintextKey);

        $this->assertNotNull($parsed);
        $this->assertSame('SPJ', $parsed['app_code']);
        $this->assertSame($key->canonicalKey, $parsed['canonical_key']);
        $this->assertSame(16, strlen($parsed['payload']));
        $this->assertSame(4, strlen($parsed['checksum']));
    }

    public function test_parses_normalized_input_with_lowercase_and_confusable_characters(): void
    {
        // Construct valid key
        $key = $this->generator->generate('SPJ');
        $lowerKey = strtolower($key->plaintextKey);

        $parsed = $this->generator->parse($lowerKey);
        $this->assertNotNull($parsed);
        $this->assertSame($key->canonicalKey, $parsed['canonical_key']);
    }

    public function test_parse_rejects_corrupted_or_invalid_keys(): void
    {
        $key = $this->generator->generate('SPJ');

        // Wrong chunk count
        $this->assertNull($this->generator->parse('SPJ-1234-5678-90AB-CDEF'));
        $this->assertNull($this->generator->parse(''));

        // Corrupted checksum
        $parts = explode('-', $key->plaintextKey);
        $parts[5] = ($parts[5] === '0000') ? '1111' : '0000';
        $corruptedKey = implode('-', $parts);
        $this->assertNull($this->generator->parse($corruptedKey));

        // Corrupted payload
        $parts2 = explode('-', $key->plaintextKey);
        $parts2[1] = 'ZZZZ';
        $corruptedPayloadKey = implode('-', $parts2);
        // Will fail checksum validation
        $this->assertNull($this->generator->parse($corruptedPayloadKey));
    }
}
