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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ClientActivationApiTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1|key_app_a');

        $this->keyGenerator = new LicenseKeyGenerator();

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

        $this->plainSecretA = 'secret_test_app_a_1234567890';
        $this->apiKeyA = ApplicationApiKey::create([
            'application_id' => $this->appA->id,
            'key_id' => 'key_app_a',
            'key_hash' => hash('sha256', $this->plainSecretA),
            'name' => 'SPJ API Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $this->plainSecretB = 'secret_test_app_b_1234567890';
        $this->apiKeyB = ApplicationApiKey::create([
            'application_id' => $this->appB->id,
            'key_id' => 'key_app_b',
            'key_hash' => hash('sha256', $this->plainSecretB),
            'name' => 'KATR API Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);
    }

    private function createLicense(
        Application $app,
        LicenseStatus $status = LicenseStatus::UNUSED,
        ?string $expiresAt = null
    ): array {
        $generated = $this->keyGenerator->generate($app->code);

        $license = License::create([
            'application_id' => $app->id,
            'key_hash' => $generated->keyHash,
            'key_masked' => $generated->keyMasked,
            'customer_name' => 'Budi Santoso',
            'customer_email' => 'budi@example.com',
            'status' => $status,
            'expires_at' => $expiresAt ? now('UTC')->modify($expiresAt) : null,
        ]);

        return [$license, $generated->plaintextKey];
    }

    // ==========================================
    // 1. API AUTHENTICATION TESTS
    // ==========================================

    public function test_rejects_missing_api_credentials(): void
    {
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'SPJ-0000-0000-0000-0000-0000',
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

    public function test_rejects_invalid_api_key_id(): void
    {
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'SPJ-0000-0000-0000-0000-0000',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => 'non_existent_key_id',
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

    public function test_rejects_invalid_api_secret(): void
    {
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'SPJ-0000-0000-0000-0000-0000',
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => 'wrong_secret_value',
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

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'SPJ-0000-0000-0000-0000-0000',
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

    public function test_rejects_expired_api_key(): void
    {
        $this->apiKeyA->update(['expires_at' => now('UTC')->subDay()]);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'SPJ-0000-0000-0000-0000-0000',
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
            'key_id' => 'key_inactive',
            'key_hash' => hash('sha256', $plainSecret),
            'name' => 'Inactive Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => 'INACT-0000-0000-0000-0000-0000',
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
    // 2. VALIDATION & APPLICATION BOUNDARY TESTS
    // ==========================================

    public function test_validates_request_body_fields(): void
    {
        $response = $this->postJson('/api/v1/license/activate', [], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                ],
            ]);
    }

    public function test_rejects_corrupt_license_key_checksum(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA);

        // Modify last char to break checksum
        $parts = explode('-', $plainKey);
        $parts[5] = ($parts[5] === '0000') ? '1111' : '0000';
        $corruptKey = implode('-', $parts);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $corruptKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_LICENSE_KEY',
                ],
            ]);
    }

    public function test_rejects_malformed_domain_input(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'http://',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_DOMAIN',
                ],
            ]);
    }

    public function test_rejects_cross_application_license_activation(): void
    {
        // License belongs to App B (KATR)
        [$licenseB, $plainKeyB] = $this->createLicense($this->appB);

        // App A credentials attempt to activate App B license
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKeyB,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_LICENSE',
                ],
            ]);

        // Verify failure logged
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->appA->id,
            'license_id' => $licenseB->id,
            'event' => LicenseLogEvent::ACTIVATION_FAILED->value,
            'actor_type' => LicenseLogActorType::CLIENT->value,
            'actor_key_id' => $this->apiKeyA->key_id,
        ]);
    }

    // ==========================================
    // 3. ACTIVATION LIFECYCLE & TOKEN ISSUANCE
    // ==========================================

    public function test_successfully_activates_unused_license(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'https://user:pass@SUB.EXAMPLE.COM:8443/shop?ref=123#frag',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'activated' => true,
                    'canonical_domain' => 'sub.example.com',
                    'status' => 'ACTIVE',
                    'license_key_masked' => $license->key_masked,
                ],
            ]);

        // License transition to ACTIVE
        $this->assertSame(LicenseStatus::ACTIVE, $license->fresh()->status);

        // Activation record created with canonical domain
        $activation = Activation::where('license_id', $license->id)->first();
        $this->assertNotNull($activation);
        $this->assertSame('sub.example.com', $activation->canonical_domain);
        $this->assertNotNull($activation->token_id);
        $this->assertNotNull($activation->token_expires_at);

        // Audit log created
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->appA->id,
            'license_id' => $license->id,
            'event' => LicenseLogEvent::ACTIVATION_SUCCESS->value,
            'actor_type' => LicenseLogActorType::CLIENT->value,
            'actor_key_id' => $this->apiKeyA->key_id,
        ]);

        // Verify token cryptographically
        $token = $response->json('data.token');
        $this->assertNotNull($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame($activation->token_id, $payload['jti']);
        $this->assertSame('sub.example.com', $payload['dom']);
        $this->assertSame('SPJ', $payload['aud']);
        $this->assertSame($license->key_masked, $payload['sub']);

        // Verify Ed25519 signature
        $signer = app(Ed25519TokenSigner::class);
        $verifiedPayload = $signer->verify($token);
        $this->assertNotNull($verifiedPayload);
        $this->assertSame($payload['jti'], $verifiedPayload['jti']);
    }

    public function test_idempotent_reactivation_on_same_canonical_domain(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        // First activation
        $res1 = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res1->assertStatus(200);
        $tokenId1 = $res1->json('data.token_id');
        $token1 = $res1->json('data.token');

        // Second activation with slightly different URL syntax that canonicalizes to the same domain
        $res2 = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'https://EXAMPLE.COM:443/some/path.',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res2->assertStatus(200);
        $tokenId2 = $res2->json('data.token_id');
        $token2 = $res2->json('data.token');

        // New token generated with distinct JTI
        $this->assertNotSame($tokenId1, $tokenId2);
        $this->assertNotSame($token1, $token2);

        // Activation table updated with the newest token_id
        $this->assertSame($tokenId2, $license->fresh()->activation->token_id);
    }

    public function test_rejects_active_license_rebind_to_different_domain(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        // Activate on example.com
        $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ])->assertStatus(200);

        // Attempt activation on different domain (evil-example.com)
        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'evil-example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'LICENSE_ALREADY_BOUND',
                ],
            ]);

        // Attempt activation on www.example.com (www is NOT equivalent to bare domain)
        $responseWww = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'www.example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $responseWww->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'LICENSE_ALREADY_BOUND',
                ],
            ]);
    }

    public function test_rejects_suspended_license(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::SUSPENDED);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
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

    public function test_rejects_revoked_license(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::REVOKED);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
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
        // Status in DB is UNUSED, but expires_at was 1 hour ago
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED, '-1 hour');

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
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
    // 4. SECURITY & DOMAIN BINDING EDGE CASES
    // ==========================================

    public function test_security_binding_prevents_suffix_and_subdomain_hijack(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        // Bound to example.com
        $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ])->assertStatus(200);

        $attackerDomains = [
            'evil-example.com',
            'example.com.evil.com',
            'www.example.com',
            'sub.example.com',
            'notexample.com',
            'example.org',
            '1.example.com',
        ];

        foreach ($attackerDomains as $domain) {
            $response = $this->postJson('/api/v1/license/activate', [
                'license_key' => $plainKey,
                'domain' => $domain,
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'success' => false,
                    'error' => [
                        'code' => 'LICENSE_ALREADY_BOUND',
                    ],
                ]);
        }
    }

    public function test_ports_and_url_paths_do_not_create_alternate_canonical_identities(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        // First activate on custom port with path
        $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'https://example.com:8080/app/index.php?v=1#section',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ])->assertStatus(200);

        $this->assertSame('example.com', $license->fresh()->activation->canonical_domain);

        // Same domain via different port and uppercase and trailing dot -> successfully idempotent
        $equivalentUrls = [
            'http://EXAMPLE.COM:443',
            'https://example.com.',
            'https://user:pass@example.com:3000/another/path',
            'example.com',
        ];

        foreach ($equivalentUrls as $url) {
            $res = $this->postJson('/api/v1/license/activate', [
                'license_key' => $plainKey,
                'domain' => $url,
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $res->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'canonical_domain' => 'example.com',
                    ],
                ]);
        }
    }

    public function test_token_ttl_is_bounded_by_license_expiry(): void
    {
        // 1. Shorter license: expires in 2 days (< 7 day token TTL)
        [$licenseShort, $plainKeyShort] = $this->createLicense($this->appA, LicenseStatus::UNUSED, '+2 days');

        $resShort = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKeyShort,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $resShort->assertStatus(200);
        $tokenExpShort = $resShort->json('data.token_expires_at');
        $this->assertEqualsWithDelta(
            $licenseShort->fresh()->expires_at->timestamp,
            strtotime($tokenExpShort),
            5 // within 5 seconds
        );

        // 2. Lifetime license (expires_at = null) -> bounded by 7-day TTL
        [$licenseLifetime, $plainKeyLifetime] = $this->createLicense($this->appA, LicenseStatus::UNUSED, null);

        $resLifetime = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKeyLifetime,
            'domain' => 'example.org',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $resLifetime->assertStatus(200);
        $tokenExpLifetime = $resLifetime->json('data.token_expires_at');
        $expectedMaxExp = now('UTC')->addSeconds(604800)->timestamp;
        $this->assertEqualsWithDelta(
            $expectedMaxExp,
            strtotime($tokenExpLifetime),
            5
        );
    }

    // ==========================================
    // 5. SECRET LEAKAGE AUDIT TESTS
    // ==========================================

    public function test_zero_secret_or_private_key_leakage_in_response_and_logs(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        $response = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $responseContent = $response->getContent();

        // Must NOT leak plaintext API secret
        $this->assertStringNotContainsString($this->plainSecretA, $responseContent);

        // Must NOT leak plaintext license key payload in full (masked is OK)
        $parts = explode('-', $plainKey);
        $rawPayload = $parts[1] . $parts[2] . $parts[3] . $parts[4];
        $this->assertStringNotContainsString($rawPayload, $responseContent);

        // Check audit log records
        $logs = LicenseLog::all();
        foreach ($logs as $log) {
            $logJson = json_encode($log->payload);
            $this->assertStringNotContainsString($this->plainSecretA, $logJson);
            $this->assertStringNotContainsString($rawPayload, $logJson);
        }
    }

    // ==========================================
    // 6. RATE LIMITING TESTS
    // ==========================================

    public function test_rate_limiter_blocks_excessive_activation_requests(): void
    {
        [$license, $plainKey] = $this->createLicense($this->appA, LicenseStatus::UNUSED);

        // Make 5 requests (the limit)
        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/license/activate', [
                'license_key' => $plainKey,
                'domain' => 'example.com',
            ], [
                'X-Api-Key-Id' => $this->apiKeyA->key_id,
                'X-Api-Secret' => $this->plainSecretA,
            ]);

            $this->assertSame(200, $res->status(), "Request #{$i} failed with status " . $res->status());
        }

        // 6th request must be throttled (HTTP 429)
        $res6 = $this->postJson('/api/v1/license/activate', [
            'license_key' => $plainKey,
            'domain' => 'example.com',
        ], [
            'X-Api-Key-Id' => $this->apiKeyA->key_id,
            'X-Api-Secret' => $this->plainSecretA,
        ]);

        $res6->assertStatus(429);
    }
}
