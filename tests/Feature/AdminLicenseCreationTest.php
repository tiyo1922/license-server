<?php

namespace Tests\Feature;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Models\Application;
use App\Models\License;
use App\Models\LicenseLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminLicenseCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Application $activeApp;
    private Application $inactiveApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin Test',
            'email' => 'admin@license-server.local',
        ]);

        $this->activeApp = Application::create([
            'name' => 'Sumber Protein Jogja',
            'code' => 'SPJ',
            'is_active' => true,
        ]);

        $this->inactiveApp = Application::create([
            'name' => 'Legacy Inactive App',
            'code' => 'LEG',
            'is_active' => false,
        ]);
    }

    public function test_guest_cannot_access_license_routes(): void
    {
        $this->get('/admin/licenses')->assertRedirect('/login');
        $this->get('/admin/licenses/create')->assertRedirect('/login');
        $this->post('/admin/licenses', [])->assertRedirect('/login');

        $license = License::create([
            'application_id' => $this->activeApp->id,
            'key_hash' => hash('sha256', 'DUMMY-KEY'),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => LicenseStatus::UNUSED,
        ]);

        $this->get("/admin/licenses/{$license->id}")->assertRedirect('/login');
    }

    public function test_admin_can_view_license_index_and_create_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/licenses')
            ->assertOk()
            ->assertViewIs('admin.licenses.index')
            ->assertSee('License Management');

        $this->actingAs($this->admin)
            ->get('/admin/licenses/create')
            ->assertOk()
            ->assertViewIs('admin.licenses.create')
            ->assertSee('Sumber Protein Jogja (Code: SPJ)')
            ->assertDontSee('Legacy Inactive App');
    }

    public function test_admin_can_generate_lifetime_license_with_immediate_single_reveal(): void
    {
        $payload = [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'lifetime',
            'customer_name' => 'PT Sumber Protein Jogja',
            'customer_email' => 'contact@sumberprotein.com',
            'notes' => 'Contract reference #SPJ-2026-001',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/admin/licenses', $payload);

        $response->assertOk();
        $response->assertViewIs('admin.licenses.show');
        $response->assertSee('Newly Generated Plaintext License Key');

        $license = License::first();
        $this->assertNotNull($license);

        // Extract plaintext key from response HTML using regex pattern
        $content = $response->getContent();
        preg_match('/id="plaintext-key-box"[^>]*>\s*([A-Z0-9\-]+)\s*<\/span>/', $content, $matches);
        $this->assertNotEmpty($matches, 'Plaintext key box should be present in immediate response.');
        $plaintextKey = trim($matches[1]);

        $this->assertStringStartsWith('SPJ-', $plaintextKey);
        $this->assertSame(28, strlen($plaintextKey)); // Format: SPJ-XXXX-XXXX-XXXX-XXXX-CCCC (3 + 5*4 + 5 hyphens = 28)

        // Verify database persistence
        $this->assertSame($this->activeApp->id, $license->application_id);
        $this->assertSame(LicenseStatus::UNUSED, $license->status);
        $this->assertNull($license->expires_at);
        $this->assertSame('PT Sumber Protein Jogja', $license->customer_name);
        $this->assertSame('contact@sumberprotein.com', $license->customer_email);
        $this->assertSame('Contract reference #SPJ-2026-001', $license->notes);

        // Verify key_hash is SHA-256 of the transient plaintext key
        $this->assertSame(hash('sha256', $plaintextKey), $license->key_hash);

        // Verify plaintext key is NOT stored anywhere in the license table
        $this->assertNotSame($plaintextKey, $license->key_hash);
        $this->assertNotSame($plaintextKey, $license->key_masked);

        // Verify Audit Log
        $log = LicenseLog::where('license_id', $license->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(LicenseLogEvent::LICENSE_CREATED, $log->event);
        $this->assertSame(LicenseLogActorType::ADMIN, $log->actor_type);
        $this->assertSame($this->admin->id, $log->actor_user_id);
        $this->assertNull($log->actor_key_id);

        // Verify Audit Log payload does NOT contain plaintext key
        $this->assertArrayHasKey('key_masked', $log->payload);
        $this->assertSame($license->key_masked, $log->payload['key_masked']);
        $this->assertStringNotContainsString($plaintextKey, json_encode($log->payload));
    }

    public function test_plaintext_key_excluded_from_persistent_sessions_table(): void
    {
        // Explicitly set session driver to database for this test
        config(['session.driver' => 'database']);

        $payload = [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'lifetime',
            'customer_name' => 'Database Session Test',
            'customer_email' => 'session-test@example.com',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/admin/licenses', $payload);

        $response->assertOk();

        // Extract plaintext key from response
        preg_match('/id="plaintext-key-box"[^>]*>\s*([A-Z0-9\-]+)\s*<\/span>/', $response->getContent(), $matches);
        $plaintextKey = trim($matches[1]);

        // Inspect all session rows in the database
        $sessionRows = DB::table('sessions')->get();
        foreach ($sessionRows as $sessionRow) {
            $rawPayload = $sessionRow->payload;
            $this->assertStringNotContainsString($plaintextKey, $rawPayload, 'Plaintext key must not exist in raw session payload.');

            $decoded = base64_decode($rawPayload, true);
            if ($decoded !== false) {
                $this->assertStringNotContainsString($plaintextKey, $decoded, 'Plaintext key must not exist in decoded session payload.');
            }
        }
    }

    public function test_subsequent_get_show_displays_only_masked_key(): void
    {
        // 1. Create a license via POST
        $postResponse = $this->actingAs($this->admin)->post('/admin/licenses', [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'lifetime',
        ]);

        preg_match('/id="plaintext-key-box"[^>]*>\s*([A-Z0-9\-]+)\s*<\/span>/', $postResponse->getContent(), $matches);
        $plaintextKey = trim($matches[1]);
        $license = License::first();

        // 2. Subsequent GET request to show page (e.g. reload or direct navigation)
        $getResponse = $this->actingAs($this->admin)
            ->get(route('admin.licenses.show', $license));

        $getResponse->assertOk();
        $getResponse->assertDontSee($plaintextKey);
        $getResponse->assertDontSee('Newly Generated Plaintext License Key');
        $getResponse->assertSee($license->key_masked);
    }

    public function test_direct_show_without_creation_context_is_masked_only(): void
    {
        $license = License::create([
            'application_id' => $this->activeApp->id,
            'key_hash' => hash('sha256', 'SPJ-1234-5678-90AB-CDEF-K92X'),
            'key_masked' => 'SPJ-****-****-****-****-K92X',
            'status' => LicenseStatus::UNUSED,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.licenses.show', $license));

        $response->assertOk();
        $response->assertDontSee('Newly Generated Plaintext License Key');
        $response->assertSee('SPJ-****-****-****-****-K92X');
    }

    public function test_admin_can_generate_time_bound_license(): void
    {
        $futureDate = now()->addYear()->format('Y-m-d H:i:s');

        $payload = [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'custom',
            'expires_at' => $futureDate,
            'customer_name' => 'PT Mitra Sejahtera',
            'customer_email' => 'admin@mitra.co.id',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/admin/licenses', $payload);

        $response->assertOk();
        $license = License::first();
        $this->assertNotNull($license);

        $this->assertNotNull($license->expires_at);
        $this->assertSame(
            now()->addYear()->format('Y-m-d H:i'),
            $license->expires_at->format('Y-m-d H:i')
        );
    }

    public function test_rejects_inactive_application(): void
    {
        $payload = [
            'application_id' => $this->inactiveApp->id,
            'validity_type' => 'lifetime',
        ];

        $response = $this->actingAs($this->admin)
            ->post('/admin/licenses', $payload);

        $response->assertSessionHasErrors('application_id');
        $this->assertDatabaseCount('licenses', 0);
        $this->assertDatabaseCount('license_logs', 0);
    }

    public function test_rejects_past_expiration_date(): void
    {
        $pastDate = now()->subDay()->format('Y-m-d H:i:s');

        $payload = [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'custom',
            'expires_at' => $pastDate,
        ];

        $response = $this->actingAs($this->admin)
            ->post('/admin/licenses', $payload);

        $response->assertSessionHasErrors('expires_at');
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_plaintext_key_excluded_from_application_logs(): void
    {
        Log::spy();

        $payload = [
            'application_id' => $this->activeApp->id,
            'validity_type' => 'lifetime',
        ];

        $response = $this->actingAs($this->admin)->post('/admin/licenses', $payload);
        $response->assertOk();

        preg_match('/id="plaintext-key-box"[^>]*>\s*([A-Z0-9\-]+)\s*<\/span>/', $response->getContent(), $matches);
        $plaintextKey = trim($matches[1]);

        Log::shouldNotHaveReceived('info', function ($message) use ($plaintextKey) {
            return is_string($message) && str_contains($message, $plaintextKey);
        });
        Log::shouldNotHaveReceived('error', function ($message) use ($plaintextKey) {
            return is_string($message) && str_contains($message, $plaintextKey);
        });
        Log::shouldNotHaveReceived('warning', function ($message) use ($plaintextKey) {
            return is_string($message) && str_contains($message, $plaintextKey);
        });
        Log::shouldNotHaveReceived('debug', function ($message) use ($plaintextKey) {
            return is_string($message) && str_contains($message, $plaintextKey);
        });
    }

    public function test_exceptions_do_not_expose_plaintext_key_or_secrets(): void
    {
        $generator = new \App\Services\License\LicenseKeyGenerator();
        $generatedKey = $generator->generate('SPJ');

        $exception = new \App\Exceptions\LicenseGenerationException('Unable to generate a unique license key after 3 attempts.');
        $this->assertStringNotContainsString($generatedKey->plaintextKey, $exception->getMessage());
        $this->assertStringNotContainsString('password', strtolower($exception->getMessage()));
        $this->assertStringNotContainsString('secret', strtolower($exception->getMessage()));
    }
}

