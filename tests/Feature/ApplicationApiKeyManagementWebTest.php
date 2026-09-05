<?php

namespace Tests\Feature;

use App\Enums\ApplicationApiKeyStatus;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\ApplicationApiKey;
use App\Models\LicenseLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationApiKeyManagementWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->create(['email' => 'admin@example.com']);
        $this->application = Application::create([
            'code' => 'WEB-TEST-APP',
            'name' => 'Web Test Application',
            'is_active' => true,
        ]);
    }

    /**
     * Guest is redirected to login on API key routes.
     */
    public function test_guest_is_redirected_to_login_on_api_key_routes(): void
    {
        $this->post("/admin/applications/{$this->application->id}/keys", [
            'name' => 'Unauthorized Key',
        ])->assertRedirect('/login');

        $this->post("/admin/applications/{$this->application->id}/keys/1/revoke")->assertRedirect('/login');
    }

    /**
     * Admin generates API key: single-reveal plaintext secret in response, hash in database.
     */
    public function test_admin_can_generate_api_key_with_single_reveal_secret(): void
    {
        $response = $this->actingAs($this->adminUser)->post("/admin/applications/{$this->application->id}/keys", [
            'name' => 'Production Webhook Key',
        ]);

        $response->assertOk()
            ->assertSee('Newly Generated API Secret (Single Reveal)')
            ->assertSee('Production Webhook Key')
            ->assertSee('ak_')
            ->assertSee('sec_');

        // Extract key_id from database
        $apiKey = ApplicationApiKey::where('application_id', $this->application->id)
            ->where('name', 'Production Webhook Key')
            ->first();

        $this->assertNotNull($apiKey);
        $this->assertSame(ApplicationApiKeyStatus::ACTIVE, $apiKey->status);
        $this->assertStringStartsWith('ak_', $apiKey->key_id);
        $this->assertSame(64, strlen($apiKey->key_hash));

        // Audit log verified
        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::API_KEY_CREATED->value,
            'actor_key_id' => $apiKey->key_id,
            'actor_user_id' => $this->adminUser->id,
        ]);

        // Subsequent GET does NOT display the secret
        $getResponse = $this->actingAs($this->adminUser)->get("/admin/applications/{$this->application->id}");
        $getResponse->assertOk()
            ->assertDontSee('Newly Generated API Secret (Single Reveal)')
            ->assertSee($apiKey->key_id);
    }

    /**
     * Plaintext secret is never stored in DB-backed sessions table or audit logs.
     */
    public function test_secret_is_never_stored_in_sessions_table_or_audit_logs(): void
    {
        $response = $this->actingAs($this->adminUser)->post("/admin/applications/{$this->application->id}/keys", [
            'name' => 'Session Isolation Key',
        ]);

        // Capture the secret from the response HTML
        preg_match('/sec_[a-zA-Z0-9]{48}/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Secret was not found in response body');
        $secret = $matches[0];

        // Check sessions table
        $sessions = DB::table('sessions')->get();
        foreach ($sessions as $session) {
            $this->assertStringNotContainsString($secret, $session->payload);
        }

        // Check audit logs
        $logs = LicenseLog::where('application_id', $this->application->id)->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString($secret, json_encode($log->payload));
        }
    }

    /**
     * Admin can revoke an active API key terminally.
     */
    public function test_admin_can_revoke_api_key(): void
    {
        $apiKey = ApplicationApiKey::create([
            'application_id' => $this->application->id,
            'key_id' => 'ak_revoke_test_123456789012',
            'key_hash' => hash('sha256', 'sec_dummy_secret_for_revocation_test_1234567890123456'),
            'name' => 'To Be Revoked Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->adminUser)->post("/admin/applications/{$this->application->id}/keys/{$apiKey->id}/revoke", [
            'reason' => 'Compromised developer machine',
        ]);

        $response->assertRedirect("/admin/applications/{$this->application->id}")
            ->assertSessionHas('success');

        $apiKey->refresh();
        $this->assertSame(ApplicationApiKeyStatus::REVOKED, $apiKey->status);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::API_KEY_REVOKED->value,
            'actor_key_id' => $apiKey->key_id,
            'payload->reason' => 'Compromised developer machine',
        ]);
    }

    /**
     * API authentication using newly created key works on /api/v1/license/activate.
     */
    public function test_api_authentication_works_with_generated_credentials(): void
    {
        $response = $this->actingAs($this->adminUser)->post("/admin/applications/{$this->application->id}/keys", [
            'name' => 'Integration Key',
        ]);

        preg_match('/ak_[a-zA-Z0-9]{24}/', $response->getContent(), $keyIdMatches);
        preg_match('/sec_[a-zA-Z0-9]{48}/', $response->getContent(), $secretMatches);

        $keyId = $keyIdMatches[0];
        $secret = $secretMatches[0];

        // Call API endpoint with generated credentials
        $apiResponse = $this->withHeaders([
            'X-Api-Key-Id' => $keyId,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', [
            'license_key' => 'NON-EXISTENT-KEY',
            'domain' => 'sumberprotein.id',
        ]);

        // Should pass authentication middleware and fail on invalid license key (422) instead of 401
        $apiResponse->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_LICENSE_KEY');
    }

    /**
     * API authentication fails immediately when key is revoked.
     */
    public function test_api_authentication_fails_when_key_is_revoked(): void
    {
        $secret = 'sec_test_secret_for_revoked_key_12345678901234567890';
        $apiKey = ApplicationApiKey::create([
            'application_id' => $this->application->id,
            'key_id' => 'ak_revoked_auth_test_1234',
            'key_hash' => hash('sha256', $secret),
            'name' => 'Revoked Auth Key',
            'status' => ApplicationApiKeyStatus::REVOKED,
        ]);

        $apiResponse = $this->withHeaders([
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', [
            'license_key' => 'TEST-KEY',
            'domain' => 'sumberprotein.id',
        ]);

        $apiResponse->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'API key has been revoked.');
    }

    /**
     * API authentication fails when application is inactive.
     */
    public function test_api_authentication_fails_when_application_is_inactive(): void
    {
        $this->application->update(['is_active' => false]);

        $secret = 'sec_test_secret_inactive_app_1234567890123456789012';
        $apiKey = ApplicationApiKey::create([
            'application_id' => $this->application->id,
            'key_id' => 'ak_inactive_app_key_1234',
            'key_hash' => hash('sha256', $secret),
            'name' => 'Inactive App Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
        ]);

        $apiResponse = $this->withHeaders([
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', [
            'license_key' => 'TEST-KEY',
            'domain' => 'sumberprotein.id',
        ]);

        $apiResponse->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAUTHORIZED')
            ->assertJsonPath('error.message', 'Application is inactive.');
    }

    /**
     * Middleware coalesces last_used_at updates to max 1 write per 5 minutes.
     */
    public function test_middleware_coalesces_last_used_at_updates(): void
    {
        $secret = 'sec_test_secret_coalesce_123456789012345678901234';
        $apiKey = ApplicationApiKey::create([
            'application_id' => $this->application->id,
            'key_id' => 'ak_coalesce_key_12345678',
            'key_hash' => hash('sha256', $secret),
            'name' => 'Coalesce Test Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
            'last_used_at' => null,
        ]);

        // Request 1: at T=0
        $t0 = Carbon::create(2026, 9, 6, 12, 0, 0, 'UTC');
        Carbon::setTestNow($t0);

        $this->withHeaders([
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', ['license_key' => 'X', 'domain' => 'sumberprotein.id']);

        $apiKey->refresh();
        $this->assertNotNull($apiKey->last_used_at);
        $this->assertSame($t0->toIso8601String(), $apiKey->last_used_at->toIso8601String());

        // Request 2: at T = 2 minutes later (within 5-min coalescing window -> should NOT write)
        $t2 = $t0->copy()->addMinutes(2);
        Carbon::setTestNow($t2);

        $this->withHeaders([
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', ['license_key' => 'X', 'domain' => 'sumberprotein.id']);

        $apiKey->refresh();
        $this->assertSame($t0->toIso8601String(), $apiKey->last_used_at->toIso8601String()); // Still T=0

        // Request 3: at T = 6 minutes later (outside 5-min window -> updates to T=6)
        $t6 = $t0->copy()->addMinutes(6);
        Carbon::setTestNow($t6);

        $this->withHeaders([
            'X-Api-Key-Id' => $apiKey->key_id,
            'X-Api-Secret' => $secret,
        ])->postJson('/api/v1/license/activate', ['license_key' => 'X', 'domain' => 'sumberprotein.id']);

        $apiKey->refresh();
        $this->assertSame($t6->toIso8601String(), $apiKey->last_used_at->toIso8601String()); // Updated to T=6

        Carbon::setTestNow(); // Reset test clock
    }
}
