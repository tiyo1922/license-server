<?php

namespace Tests\Feature;

use App\Enums\ApplicationApiKeyStatus;
use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Models\LicenseLog;
use App\Models\User;
use App\Services\License\ApiKeyManagementService;
use App\Services\License\ApplicationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class ApiKeyManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ApiKeyManagementService $keyService;
    protected Application $application;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyService = new ApiKeyManagementService();
        $appService = new ApplicationManagementService();
        $this->application = $appService->createApplication([
            'code' => 'TEST-APP',
            'name' => 'Test Application',
        ]);
        $this->adminUser = User::factory()->create(['email' => 'admin@example.com']);
    }

    /**
     * 11 & 12. Generates credentials with exact ak_ and sec_ prefixes and lengths.
     */
    public function test_generates_api_key_with_exact_format_and_entropy(): void
    {
        $result = $this->keyService->createApiKey($this->application, [
            'name' => 'Production Server Key',
        ], $this->adminUser->id);

        $apiKey = $result['apiKey'];
        $secret = $result['plaintextSecret'];

        // key_id: ak_ + 24 chars = 27 chars
        $this->assertStringStartsWith('ak_', $apiKey->key_id);
        $this->assertSame(27, strlen($apiKey->key_id));
        $this->assertMatchesRegularExpression('/^ak_[a-zA-Z0-9]{24}$/', $apiKey->key_id);

        // secret: sec_ + 48 chars = 52 chars
        $this->assertStringStartsWith('sec_', $secret);
        $this->assertSame(52, strlen($secret));
        $this->assertMatchesRegularExpression('/^sec_[a-zA-Z0-9]{48}$/', $secret);

        // Status is ACTIVE
        $this->assertSame(ApplicationApiKeyStatus::ACTIVE, $apiKey->status);
    }

    /**
     * 13, 14, 15, 16, 17. Hash-only persistence, zero secret in DB/logs/audit.
     */
    public function test_persists_hash_only_and_excludes_secret_from_db_and_audit(): void
    {
        Log::spy();

        $result = $this->keyService->createApiKey($this->application, [
            'name' => 'Hash Test Key',
        ], $this->adminUser->id);

        $apiKey = $result['apiKey'];
        $secret = $result['plaintextSecret'];

        // Database record check
        $persisted = ApplicationApiKey::findOrFail($apiKey->id);
        $this->assertSame(hash('sha256', $secret), $persisted->key_hash);
        $this->assertStringNotContainsString($secret, json_encode($persisted->toArray()));

        // Audit log check
        $auditLog = LicenseLog::where('application_id', $this->application->id)
            ->where('event', LicenseLogEvent::API_KEY_CREATED)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($this->adminUser->id, $auditLog->actor_user_id);
        $this->assertSame(LicenseLogActorType::ADMIN, $auditLog->actor_type);
        $this->assertSame($apiKey->key_id, $auditLog->actor_key_id);

        // Plaintext secret and hash must NOT be in audit payload
        $auditPayloadJson = json_encode($auditLog->payload);
        $this->assertStringNotContainsString($secret, $auditPayloadJson);
        $this->assertStringNotContainsString($apiKey->key_hash, $auditPayloadJson);

        // Log spy check
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * 19. Expiration validation (past date rejected, future accepted).
     */
    public function test_validates_expiration_timestamp(): void
    {
        // Past date rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key expiration date must be in the future.');

        $this->keyService->createApiKey($this->application, [
            'name' => 'Expired Key Attempt',
            'expires_at' => now('UTC')->subDay()->toIso8601String(),
        ]);
    }

    /**
     * Valid future expiration accepted.
     */
    public function test_accepts_valid_future_expiration(): void
    {
        $futureTime = now('UTC')->addMonths(6);
        $result = $this->keyService->createApiKey($this->application, [
            'name' => '6 Month Key',
            'expires_at' => $futureTime->toIso8601String(),
        ]);

        $this->assertNotNull($result['apiKey']->expires_at);
        $this->assertSame($futureTime->format('Y-m-d H:i:s'), $result['apiKey']->expires_at->format('Y-m-d H:i:s'));
    }

    /**
     * 20 & 21. Terminal revocation.
     */
    public function test_revokes_api_key_terminally(): void
    {
        $result = $this->keyService->createApiKey($this->application, [
            'name' => 'Revoke Test Key',
        ]);

        $apiKey = $result['apiKey'];
        $this->assertSame(ApplicationApiKeyStatus::ACTIVE, $apiKey->status);

        // Revoke
        $revokedKey = $this->keyService->revokeApiKey(
            $this->application,
            $apiKey,
            'Compromised credential rotation',
            $this->adminUser->id
        );

        $this->assertSame(ApplicationApiKeyStatus::REVOKED, $revokedKey->status);
        $this->assertDatabaseHas('application_api_keys', [
            'id' => $apiKey->id,
            'status' => ApplicationApiKeyStatus::REVOKED->value,
        ]);

        // Audit log verified
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::API_KEY_REVOKED->value,
            'actor_key_id' => $apiKey->key_id,
            'payload->reason' => 'Compromised credential rotation',
        ]);
    }

    /**
     * 22. Cross-application key operation is rejected.
     */
    public function test_rejects_key_operation_on_wrong_application(): void
    {
        $appService = new ApplicationManagementService();
        $otherApp = $appService->createApplication([
            'code' => 'OTHER-APP',
            'name' => 'Other App',
        ]);

        $result = $this->keyService->createApiKey($this->application, [
            'name' => 'App 1 Key',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("does not belong to application [OTHER-APP]");

        // Attempting to revoke App 1's key using App 2 context
        $this->keyService->revokeApiKey($otherApp, $result['apiKey']);
    }

    /**
     * Multiple active keys can coexist for zero-downtime rotation.
     */
    public function test_multiple_active_keys_can_coexist(): void
    {
        $key1 = $this->keyService->createApiKey($this->application, ['name' => 'Key 1']);
        $key2 = $this->keyService->createApiKey($this->application, ['name' => 'Key 2']);

        $this->assertNotSame($key1['apiKey']->key_id, $key2['apiKey']->key_id);
        $this->assertNotSame($key1['apiKey']->key_hash, $key2['apiKey']->key_hash);

        $activeCount = ApplicationApiKey::where('application_id', $this->application->id)
            ->where('status', ApplicationApiKeyStatus::ACTIVE)
            ->count();

        $this->assertSame(2, $activeCount);
    }
}
