<?php

namespace Tests\Unit;

use App\Services\License\Client\ClientLicenseVerifier;
use App\Services\License\Client\VerificationStatusCode;
use App\Services\License\DomainCanonicalizer;
use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\Ed25519TokenVerifier;
use App\Services\License\Token\StaticTrustedKeyRegistry;
use PHPUnit\Framework\TestCase;

class ClientLicenseVerifierTest extends TestCase
{
    private Ed25519TokenSigner $signer;
    private Ed25519TokenVerifier $verifier;
    private StaticTrustedKeyRegistry $registry;
    private DomainCanonicalizer $canonicalizer;
    private ClientLicenseVerifier $clientVerifier;
    private string $kid = 'cls-ed25519-2026-v1';
    private string $issuer = 'license.katresnanku.com';
    private string $appCode = 'SPJ';

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $sec = sodium_crypto_sign_secretkey($keypair);
        $pub = sodium_crypto_sign_publickey($keypair);

        $this->registry = new StaticTrustedKeyRegistry([
            $this->kid => $pub,
        ]);

        $this->verifier = new Ed25519TokenVerifier($this->registry);
        $this->signer = new Ed25519TokenSigner(base64_encode($sec), base64_encode($pub), $this->kid, $this->verifier);
        $this->canonicalizer = new DomainCanonicalizer();

        $this->clientVerifier = new ClientLicenseVerifier(
            tokenVerifier: $this->verifier,
            domainCanonicalizer: $this->canonicalizer,
            expectedIssuer: $this->issuer,
            expectedApplicationCode: $this->appCode,
            clockSkewSeconds: 60
        );
    }

    private function createValidToken(array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'jti' => 'tok_' . bin2hex(random_bytes(8)),
            'iss' => $this->issuer,
            'aud' => $this->appCode,
            'sub' => 'SPJ-****-****-****-****-0000',
            'dom' => 'example.com',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 604800,
            'lic_exp' => null,
            'customer' => ['name' => 'Budi', 'email' => 'budi@example.com'],
        ], $overrides);

        return $this->signer->sign($payload);
    }

    public function test_valid_token_verification_succeeds(): void
    {
        $token = $this->createValidToken();
        $result = $this->clientVerifier->verify($token, 'https://example.com:443');

        $this->assertTrue($result->isValid);
        $this->assertSame(VerificationStatusCode::VALID, $result->statusCode);
        $this->assertSame('example.com', $result->getCanonicalDomain());
        $this->assertSame('SPJ-****-****-****-****-0000', $result->getMaskedKey());
        $this->assertNull($result->errorMessage);
    }

    public function test_rejects_malformed_token_structure(): void
    {
        $result = $this->clientVerifier->verify('not.valid.jwt.structure.here', 'example.com');

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::INVALID_STRUCTURE, $result->statusCode);
    }

    public function test_rejects_unknown_kid(): void
    {
        $foreignSigner = new Ed25519TokenSigner(null, null, 'foreign-unknown-kid');
        $token = $foreignSigner->sign([
            'jti' => 'tok_foreign',
            'iss' => $this->issuer,
            'aud' => $this->appCode,
            'sub' => 'SPJ-0000',
            'dom' => 'example.com',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com');

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::UNKNOWN_KEY, $result->statusCode);
    }

    public function test_rejects_tampered_signature(): void
    {
        $token = $this->createValidToken();
        [$h, $p, $s] = explode('.', $token);
        $tampered = "{$h}.{$p}." . Ed25519TokenVerifier::base64UrlEncode(str_repeat('X', 64));

        $result = $this->clientVerifier->verify($tampered, 'example.com');

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::INVALID_SIGNATURE, $result->statusCode);
    }

    public function test_rejects_issuer_mismatch(): void
    {
        $token = $this->createValidToken(['iss' => 'untrusted-license-authority.com']);
        $result = $this->clientVerifier->verify($token, 'example.com');

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::INVALID_ISSUER, $result->statusCode);
    }

    public function test_rejects_audience_mismatch(): void
    {
        $token = $this->createValidToken(['aud' => 'ANOTHER_APP']);
        $result = $this->clientVerifier->verify($token, 'example.com');

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::INVALID_AUDIENCE, $result->statusCode);
    }

    public function test_rejects_domain_mismatch_and_subdomain_hijack(): void
    {
        $token = $this->createValidToken(['dom' => 'example.com']);

        $mismatchedDomains = [
            'evil-example.com',
            'example.com.evil.com',
            'www.example.com',
            'sub.example.com',
            'another.org',
        ];

        foreach ($mismatchedDomains as $dom) {
            $result = $this->clientVerifier->verify($token, $dom);
            $this->assertFalse($result->isValid, "Domain {$dom} should have been rejected.");
            $this->assertSame(VerificationStatusCode::INVALID_DOMAIN, $result->statusCode);
        }
    }

    public function test_rejects_future_nbf(): void
    {
        $now = time();
        $token = $this->createValidToken([
            'nbf' => $now + 3600, // 1 hour in the future
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::NOT_YET_VALID, $result->statusCode);
    }

    public function test_rejects_expired_token(): void
    {
        $now = time();
        $token = $this->createValidToken([
            'exp' => $now - 3600, // expired 1 hour ago
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::EXPIRED, $result->statusCode);
    }

    public function test_offline_clock_skew_tolerance(): void
    {
        $now = time();

        // Expired 30 seconds ago: within 60-second clock skew tolerance -> VALID
        $tokenWithinSkew = $this->createValidToken(['exp' => $now - 30]);
        $res1 = $this->clientVerifier->verify($tokenWithinSkew, 'example.com', $now);
        $this->assertTrue($res1->isValid);
        $this->assertSame(VerificationStatusCode::VALID, $res1->statusCode);

        // Expired 90 seconds ago: outside 60-second clock skew tolerance -> EXPIRED
        $tokenOutsideSkew = $this->createValidToken(['exp' => $now - 90]);
        $res2 = $this->clientVerifier->verify($tokenOutsideSkew, 'example.com', $now);
        $this->assertFalse($res2->isValid);
        $this->assertSame(VerificationStatusCode::EXPIRED, $res2->statusCode);
    }

    public function test_rejects_expiry_inconsistency(): void
    {
        $now = time();
        // Token exp (7 days) exceeds lic_exp (2 days)
        $token = $this->createValidToken([
            'exp' => $now + 604800,
            'lic_exp' => $now + 172800,
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::EXPIRY_INCONSISTENCY, $result->statusCode);
    }

    public function test_rejects_expired_license_in_token(): void
    {
        $now = time();
        $token = $this->createValidToken([
            'exp' => $now - 100,
            'lic_exp' => $now - 100, // license expired in the past
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::LICENSE_EXPIRED, $result->statusCode);
    }

    public function test_lifetime_license_token_offline_verification_succeeds_and_identifies_lifetime(): void
    {
        $now = time();
        $token = $this->createValidToken([
            'exp' => $now + 604800,
            'lic_exp' => null, // Lifetime: no license expiry timestamp
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertTrue($result->isValid);
        $this->assertSame(VerificationStatusCode::VALID, $result->statusCode);
        $this->assertTrue($result->isLifetime());
        $this->assertNull($result->getLicenseExpiresAt());
        $this->assertSame($now + 604800, $result->getExpiresAt());
        $this->assertTrue($result->toArray()['is_lifetime']);
    }

    public function test_lifetime_token_still_enforces_token_ttl_expiration_offline(): void
    {
        $now = time();
        $token = $this->createValidToken([
            'exp' => $now - 3600, // Token TTL expired 1 hour ago
            'lic_exp' => null,   // License is lifetime
        ]);

        $result = $this->clientVerifier->verify($token, 'example.com', $now);

        $this->assertFalse($result->isValid);
        $this->assertSame(VerificationStatusCode::EXPIRED, $result->statusCode);
        $this->assertTrue($result->isLifetime());
    }
}
