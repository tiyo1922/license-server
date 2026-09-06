<?php

namespace Tests\Feature;

use App\Enums\ApplicationApiKeyStatus;
use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Models\Activation;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Models\License;
use App\Models\LicenseLog;
use App\Services\License\LicenseKeyGenerator;
use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ClientVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private Application $appA;
    private Application $appB;
    private Application $inactiveApp;
    private ApplicationApiKey $apiKeyA;
    private string $plainSecretA;
    private ApplicationApiKey $apiKeyB;
    private string $plainSecretB;
    private LicenseKeyGenerator $keyGenerator;
    private TokenSignerInterface $tokenSigner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyGenerator = new LicenseKeyGenerator();
        $this->tokenSigner = app(TokenSignerInterface::class);

        $this->appA = Application::create([
            'name' => 'Sumber Protein Jogja',
            'code' => 'SPJ',
            'is_active' => true,
        ]);

        $this->appB = Application::create([
            'name' => 'Katresnanku App',
            'code' => 'KATR',
            'is_active' => true,
        ]);

        $this->inactiveApp = Application::create([
            'name' => 'Inactive App',
            'code' => 'INACT',
            'is_active' => false,
        ]);

        $this->plainSecretA = 'secret_test_app_a_ver_12345';
        $this->apiKeyA = ApplicationApiKey::create([
            'application_id' => $this->appA->id,
            'key_id' => 'key_app_a_ver',
            'key_hash' => hash('sha256', $this->plainSecretA),
            'name' => 'SPJ API Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $this->plainSecretB = 'secret_test_app_b_ver_12345';
        $this->apiKeyB = ApplicationApiKey::create([
            'application_id' => $this->appB->id,
            'key_id' => 'key_app_b_ver',
            'key_hash' => hash('sha256', $this->plainSecretB),
            'name' => 'KATR API Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);
    }

    /**
     * Helper to activate a license and return token + license + activation models.
     */
    private function setupActivatedLicense(
        Application $app,
        ApplicationApiKey $apiKey,
        string $plainSecret,
        string $domain = 'example.com',
        ?string $licenseExpiresAt = null,
        LicenseStatus $status = LicenseStatus::UNUSED
    ): array {
        $generated = $this->keyGenerator->generate($app->code);

        $license = License::create([
            'application_id' => $app->id,
            'key_hash' => $generated->keyHash,
            'key_masked' => $generated->keyMasked,
            'customer_name' => 'Budi Santoso',
            'customer_email' => 'budi@example.com',
            'status' => $status,
            'expires_at' => $licenseExpiresAt ? now('UTC')->modify($licenseExpiresAt) : null,
        ]);

        // Activate via API
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $generated->plaintextKey,
            'domain' => $domain,
        ], [
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $plainSecret,
        ]);

        $token = $response->json('data.token');
        $activation = Activation::where('license_id', $license->id)->first();

        return [$license->fresh(), $activation, $token, $generated->plaintextKey];
    }

    // ==========================================
    // 1. API AUTHENTICATION TESTS
    // ==========================================

    public function test_rejects_missing_api_credentials(): void
    {
        $response = $this->postJson('/api/v1/license/verify', [
            'token' => 'dummy.token.value',
            'domain' => 'example.com',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_rejects_invalid_api_secret(): void
    {
        $response = $this->postJson('/api/v1/license/verify', [
            'token' => 'dummy.token.value',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => 'wrong_secret',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_rejects_revoked_api_key(): void
    {
        $this->apiKeyA->update(['status' => ApplicationApiKeyStatus::REVOKED]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => 'dummy.token.value',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
    }

    public function test_rejects_inactive_application(): void
    {
        $plainSecret = 'secret_inactive_app';
        $inactiveKey = ApplicationApiKey::create([
            'application_id' => $this->inactiveApp->id,
            'key_id' => 'key_inactive_ver',
            'key_hash' => hash('sha256', $plainSecret),
            'name' => 'Inactive Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => 'dummy.token.value',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $inactiveKey->key_id,
            'X-Api-Secret' => $plainSecret,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                ],
            ]);
    }

    // ==========================================
    // 2. TOKEN CRYPTOGRAPHIC & STRUCTURE INTEGRITY
    // ==========================================

    public function test_rejects_malformed_token_structure(): void
    {
        $response = $this->postJson('/api/v1/license/verify', [
            'token' => 'only.two.parts.are.not.three',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TOKEN',
                ],
            ]);
    }

    public function test_rejects_algorithm_confusion_attacks(): void
    {
        $unsupportedHeaders = [
            ['alg' => 'HS256', 'typ' => 'CLS-LIC-V1', 'kid' => 'cls-ed25519-2026-v1'],
            ['alg' => 'HS512', 'typ' => 'CLS-LIC-V1', 'kid' => 'cls-ed25519-2026-v1'],
            ['alg' => 'none', 'typ' => 'CLS-LIC-V1', 'kid' => 'cls-ed25519-2026-v1'],
            ['alg' => 'RS256', 'typ' => 'CLS-LIC-V1', 'kid' => 'cls-ed25519-2026-v1'],
            ['alg' => 'Ed25519', 'typ' => 'JWT', 'kid' => 'cls-ed25519-2026-v1'], // invalid typ
        ];

        foreach ($unsupportedHeaders as $hdr) {
            $hdrB64 = Ed25519TokenSigner::base64UrlEncode(json_encode($hdr));
            $payloadB64 = Ed25519TokenSigner::base64UrlEncode(json_encode(['test' => 1]));
            $sigB64 = Ed25519TokenSigner::base64UrlEncode(str_repeat('A', 64));
            $forgedToken = "{$hdrB64}.{$payloadB64}.{$sigB64}";

            $response = $this->postJson('/api/v1/license/verify', [
                'token' => $forgedToken,
                'domain' => 'example.com',
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_TOKEN_HEADER',
                    ],
                ]);
        }
    }

    public function test_rejects_unknown_kid(): void
    {
        $hdr = ['alg' => 'Ed25519', 'typ' => 'CLS-LIC-V1', 'kid' => 'unknown-kid-2099'];
        $hdrB64 = Ed25519TokenSigner::base64UrlEncode(json_encode($hdr));
        $payloadB64 = Ed25519TokenSigner::base64UrlEncode(json_encode(['test' => 1]));
        $sigB64 = Ed25519TokenSigner::base64UrlEncode(str_repeat('A', 64));
        $forgedToken = "{$hdrB64}.{$payloadB64}.{$sigB64}";

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $forgedToken,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_KEY_UNKNOWN',
                ],
            ]);
    }

    public function test_rejects_tampered_signature_or_tampered_payload(): void
    {
        [$license, $activation, $validToken] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        [$hdrB64, $payloadB64, $sigB64] = explode('.', $validToken);

        // 1. Tampered payload
        $payload = json_decode(Ed25519TokenSigner::base64UrlDecode($payloadB64), true);
        $payload['dom'] = 'hacked.example.com';
        $tamperedPayloadB64 = Ed25519TokenSigner::base64UrlEncode(json_encode($payload));
        $tamperedToken = "{$hdrB64}.{$tamperedPayloadB64}.{$sigB64}";

        $res1 = $this->postJson('/api/v1/license/verify', [
            'token' => $tamperedToken,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res1->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TOKEN_SIGNATURE',
                ],
            ]);

        // 2. Tampered signature
        $tamperedSigB64 = Ed25519TokenSigner::base64UrlEncode(str_repeat('B', 64));
        $tamperedSigToken = "{$hdrB64}.{$payloadB64}.{$tamperedSigB64}";

        $res2 = $this->postJson('/api/v1/license/verify', [
            'token' => $tamperedSigToken,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res2->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TOKEN_SIGNATURE',
                ],
            ]);
    }

    // ==========================================
    // 3. CLAIM VALIDATION & BOUNDARIES
    // ==========================================

    public function test_rejects_issuer_mismatch(): void
    {
        $payload = [
            'jti' => 'tok_issuer_mismatch',
            'iss' => 'evil-issuer.com',
            'aud' => 'SPJ',
            'sub' => 'SPJ-****-****-****-****-0000',
            'dom' => 'example.com',
            'iat' => now('UTC')->timestamp,
            'nbf' => now('UTC')->timestamp,
            'exp' => now('UTC')->addDays(7)->timestamp,
        ];

        $token = $this->tokenSigner->sign($payload);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_ISSUER_MISMATCH',
                ],
            ]);
    }

    public function test_rejects_cross_application_audience_mismatch(): void
    {
        // Token issued for App B (KATR)
        [$licenseB, $activationB, $tokenB] = $this->setupActivatedLicense(
            $this->appB,
            $this->apiKeyB,
            $this->plainSecretB
        );

        // App A credentials attempt to verify App B token
        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $tokenB,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_AUDIENCE_MISMATCH',
                ],
            ]);
    }

    public function test_rejects_not_yet_active_token(): void
    {
        $payload = [
            'jti' => 'tok_future_nbf',
            'iss' => config('license.issuer', 'license.katresnanku.com'),
            'aud' => 'SPJ',
            'sub' => 'SPJ-****-****-****-****-0000',
            'dom' => 'example.com',
            'iat' => now('UTC')->addHour()->timestamp,
            'nbf' => now('UTC')->addHour()->timestamp,
            'exp' => now('UTC')->addDays(7)->timestamp,
        ];

        $token = $this->tokenSigner->sign($payload);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_NOT_YET_VALID',
                ],
            ]);
    }

    public function test_rejects_expired_token(): void
    {
        $payload = [
            'jti' => 'tok_expired_exp',
            'iss' => config('license.issuer', 'license.katresnanku.com'),
            'aud' => 'SPJ',
            'sub' => 'SPJ-****-****-****-****-0000',
            'dom' => 'example.com',
            'iat' => now('UTC')->subDays(8)->timestamp,
            'nbf' => now('UTC')->subDays(8)->timestamp,
            'exp' => now('UTC')->subSecond()->timestamp,
        ];

        $token = $this->tokenSigner->sign($payload);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_EXPIRED',
                ],
            ]);
    }

    // ==========================================
    // 4. DOMAIN BINDING & CANONICALIZATION
    // ==========================================

    public function test_verifies_successfully_with_canonical_equivalent_urls(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA,
            'example.com'
        );

        $equivalentUrls = [
            'http://EXAMPLE.COM',
            'https://example.com:443/some/path?a=1#sec',
            'https://user:pass@example.com:8080',
            'example.com.',
        ];

        foreach ($equivalentUrls as $url) {
            $response = $this->postJson('/api/v1/license/verify', [
                'token' => $token,
                'domain' => $url,
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'valid' => true,
                        'canonical_domain' => 'example.com',
                        'status' => 'ACTIVE',
                    ],
                ]);
        }
    }

    public function test_rejects_domain_mismatch_and_suffix_attacks(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA,
            'example.com'
        );

        $attackerDomains = [
            'evil-example.com',
            'example.com.evil.com',
            'www.example.com',
            'sub.example.com',
            'notexample.com',
        ];

        foreach ($attackerDomains as $domain) {
            $response = $this->postJson('/api/v1/license/verify', [
                'token' => $token,
                'domain' => $domain,
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'TOKEN_DOMAIN_MISMATCH',
                    ],
                ]);
        }
    }

    // ==========================================
    // 5. JTI & SUPERSEDED TOKEN ENFORCEMENT
    // ==========================================

    public function test_superseded_token_is_rejected_online(): void
    {
        [$license, $activation, $tokenA, $plainKey] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA,
            'example.com'
        );

        // Verify Token A passes online
        $resA = $this->postJson('/api/v1/license/verify', [
            'token' => $tokenA,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);
        $resA->assertStatus(200);

        // Reactivate on same domain to generate Token B (superseding Token A)
        $resReactivate = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);
        $tokenB = $resReactivate->json('data.token');

        // Token A is now superseded online
        $resASuperseded = $this->postJson('/api/v1/license/verify', [
            'token' => $tokenA,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $resASuperseded->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_SUPERSEDED',
                ],
            ]);

        // Token B is active and passes online
        $resB = $this->postJson('/api/v1/license/verify', [
            'token' => $tokenB,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);
        $resB->assertStatus(200);
    }

    // ==========================================
    // 6. LIFECYCLE STATE ENFORCEMENT
    // ==========================================

    public function test_rejects_suspended_license_even_with_fresh_token(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        $license->update(['status' => LicenseStatus::SUSPENDED]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'LICENSE_SUSPENDED',
                ],
            ]);
    }

    public function test_rejects_revoked_license_even_with_fresh_token(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        $license->update(['status' => LicenseStatus::REVOKED]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'LICENSE_REVOKED',
                ],
            ]);
    }

    public function test_rejects_authoritative_expired_license(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA,
            'example.com',
            '+2 hours'
        );

        // Travel forward in time so license is authoritatively expired
        $license->update(['expires_at' => now('UTC')->subMinute()]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'LICENSE_EXPIRED',
                ],
            ]);
    }

    // ==========================================
    // 7. ROLLING TOKEN REFRESH & LAST VERIFIED AT
    // ==========================================

    public function test_verification_without_refresh_when_remaining_ttl_above_50_percent(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        $initialVerifiedAt = $activation->last_verified_at;

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'refreshed' => false,
                    'token' => $token,
                    'token_id' => $activation->token_id,
                ],
            ]);

        // last_verified_at is updated
        $this->assertNotNull($activation->fresh()->last_verified_at);

        // Audit log created for VERIFICATION_SUCCESS
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->appA->id,
            'license_id' => $license->id,
            'event' => LicenseLogEvent::VERIFICATION_SUCCESS->value,
            'actor_type' => LicenseLogActorType::CLIENT->value,
            'actor_key_id' => $this->apiKeyA->key_id,
        ]);
    }

    public function test_rolling_refresh_triggers_when_remaining_ttl_below_50_percent(): void
    {
        $serverNow = now('UTC');

        // Create token with total TTL of 7 days, but issued 4 days ago (remaining 3 days < 50%)
        $oldJti = 'tok_rolling_old_' . bin2hex(random_bytes(8));
        $iat = $serverNow->copy()->subDays(4)->timestamp;
        $exp = $serverNow->copy()->addDays(3)->timestamp;

        $payload = [
            'jti' => $oldJti,
            'iss' => config('license.issuer', 'license.katresnanku.com'),
            'aud' => 'SPJ',
            'sub' => 'SPJ-****-****-****-****-1111',
            'dom' => 'example.com',
            'iat' => $iat,
            'nbf' => $iat,
            'exp' => $exp,
            'lic_exp' => null,
            'customer' => ['name' => 'Budi Santoso', 'email' => 'budi@example.com'],
        ];

        $oldToken = $this->tokenSigner->sign($payload);

        $generated = $this->keyGenerator->generate($this->appA->code);
        $license = License::create([
            'application_id' => $this->appA->id,
            'key_hash' => $generated->keyHash,
            'key_masked' => $generated->keyMasked,
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $activation = Activation::create([
            'license_id' => $license->id,
            'canonical_domain' => 'example.com',
            'token_id' => $oldJti,
            'token_expires_at' => now('UTC')->addDays(3),
            'activated_at' => now('UTC')->subDays(4),
            'last_verified_at' => now('UTC')->subDays(4),
        ]);

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $oldToken,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'refreshed' => true,
                ],
            ]);

        $newToken = $response->json('data.token');
        $newTokenId = $response->json('data.token_id');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNotSame($oldJti, $newTokenId);

        // DB activation updated with new token generation
        $this->assertSame($newTokenId, $activation->fresh()->token_id);

        // Audit log created for TOKEN_REFRESHED
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->appA->id,
            'license_id' => $license->id,
            'event' => LicenseLogEvent::TOKEN_REFRESHED->value,
            'actor_type' => LicenseLogActorType::CLIENT->value,
            'actor_key_id' => $this->apiKeyA->key_id,
        ]);
    }

    // ==========================================
    // 8. RATE LIMITING TESTS
    // ==========================================

    public function test_verify_rate_limiter_blocks_after_60_requests_per_minute(): void
    {
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        RateLimiter::clear($this->apiKeyA->key_id . '|example.com');

        for ($i = 0; $i < 60; $i++) {
            $res = $this->postJson('/api/v1/license/verify', [
                'token' => $token,
                'domain' => 'example.com',
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $this->assertSame(200, $res->status(), "Request #{$i} failed with status " . $res->status());
        }

        // 61st request receives HTTP 429
        $res61 = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res61->assertStatus(429)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ],
            ]);
    }

    // ==========================================
    // 9. SECRET & TOKEN LEAKAGE AUDIT
    // ==========================================

    public function test_zero_secret_or_raw_token_leakage_in_logs_and_exceptions(): void
    {
        [$license, $activation, $token, $plainKey] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA
        );

        $response = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $responseContent = $response->getContent();

        // Must NOT leak plaintext API secret
        $this->assertStringNotContainsString($this->plainSecretA, $responseContent);

        // Logs audit
        $logs = LicenseLog::all();
        foreach ($logs as $log) {
            $logJson = json_encode($log->payload);
            $this->assertStringNotContainsString($this->plainSecretA, $logJson);
            // Full raw token must NOT be stored in log payload
            $this->assertStringNotContainsString($token, $logJson);
        }
    }

    public function test_lifetime_license_verification_maintains_null_expires_at_and_supports_rolling_refresh(): void
    {
        // 1. Setup activated lifetime license
        [$license, $activation, $token] = $this->setupActivatedLicense(
            $this->appA,
            $this->apiKeyA,
            $this->plainSecretA,
            'lifetime.sumberprotein.com',
            null
        );

        $this->assertNull($license->expires_at);
        $this->assertTrue($license->isLifetime());

        // 2. Verify online
        $verifyRes = $this->postJson('/api/v1/license/verify', [
            'token' => $token,
            'domain' => 'lifetime.sumberprotein.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $verifyRes->assertStatus(200);
        $verifyRes->assertJson([
            'success' => true,
            'data' => [
                'valid' => true,
                'status' => 'ACTIVE',
                'canonical_domain' => 'lifetime.sumberprotein.com',
                'expires_at' => null,
            ],
        ]);
    }
}
