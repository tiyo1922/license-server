<?php

namespace Tests\Feature;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Enums\LicenseStatus;
use App\Models\Activation;
use App\Models\Application;
use App\Models\License;
use App\Models\LicenseLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncExpiredLicensesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->application = Application::create([
            'name' => 'Sumber Protein Jogja',
            'code' => 'SPJ',
            'is_active' => true,
        ]);
    }

    private function createActivation(License $license, string $domain = 'spj.test'): Activation
    {
        return Activation::create([
            'license_id' => $license->id,
            'canonical_domain' => $domain,
            'ip_address' => '127.0.0.1',
            'token_id' => 'TOK-' . uniqid(),
            'token_expires_at' => now()->addDays(7),
            'activated_at' => now(),
            'last_verified_at' => now(),
        ]);
    }

    public function test_sync_expired_command_updates_expired_licenses_idempotently(): void
    {
        $past = Carbon::now('UTC')->subHour();
        $future = Carbon::now('UTC')->addYear();

        // 1. Expired Active License
        $expiredActive = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-1'),
            'key_masked' => 'SPJ-****-****-****-****-0001',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => $past,
        ]);
        $this->createActivation($expiredActive, 'active.test');

        // 2. Expired Suspended License
        $expiredSuspended = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-2'),
            'key_masked' => 'SPJ-****-****-****-****-0002',
            'status' => LicenseStatus::SUSPENDED,
            'expires_at' => $past,
        ]);
        $this->createActivation($expiredSuspended, 'suspended.test');

        // 3. Expired Unused License
        $expiredUnused = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-3'),
            'key_masked' => 'SPJ-****-****-****-****-0003',
            'status' => LicenseStatus::UNUSED,
            'expires_at' => $past,
        ]);

        // 4. Valid Unexpired Active License (should remain ACTIVE)
        $validActive = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-4'),
            'key_masked' => 'SPJ-****-****-****-****-0004',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => $future,
        ]);

        // 5. Lifetime License (should remain ACTIVE)
        $lifetime = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-5'),
            'key_masked' => 'SPJ-****-****-****-****-0005',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => null,
        ]);

        // First Command Execution
        $this->artisan('licenses:sync-expired')
            ->expectsOutput('Starting license expiration synchronization...')
            ->expectsOutput('Successfully synchronized 3 expired license(s).')
            ->assertSuccessful();

        // Verify status updates
        $this->assertSame(LicenseStatus::EXPIRED, $expiredActive->fresh()->status);
        $this->assertSame(LicenseStatus::EXPIRED, $expiredSuspended->fresh()->status);
        $this->assertSame(LicenseStatus::EXPIRED, $expiredUnused->fresh()->status);
        $this->assertSame(LicenseStatus::ACTIVE, $validActive->fresh()->status);
        $this->assertSame(LicenseStatus::ACTIVE, $lifetime->fresh()->status);

        // Verify audit logs created for expired licenses
        $this->assertDatabaseHas('license_logs', [
            'license_id' => $expiredActive->id,
            'event' => LicenseLogEvent::LICENSE_EXPIRED->value,
            'actor_type' => LicenseLogActorType::SYSTEM->value,
        ]);

        $this->assertDatabaseHas('license_logs', [
            'license_id' => $expiredSuspended->id,
            'event' => LicenseLogEvent::LICENSE_EXPIRED->value,
            'actor_type' => LicenseLogActorType::SYSTEM->value,
        ]);

        $this->assertDatabaseHas('license_logs', [
            'license_id' => $expiredUnused->id,
            'event' => LicenseLogEvent::LICENSE_EXPIRED->value,
            'actor_type' => LicenseLogActorType::SYSTEM->value,
        ]);

        // Verify total audit logs is exactly 3
        $this->assertDatabaseCount('license_logs', 3);

        // Second Command Execution (Idempotency test: 0 synchronized, 0 new audit logs)
        $this->artisan('licenses:sync-expired')
            ->expectsOutput('Successfully synchronized 0 expired license(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('license_logs', 3);

        // Verify that after sync, origin is preserved accurately:
        $this->assertSame(LicenseStatus::ACTIVE, $expiredActive->fresh()->getOriginStatusBeforeExpiration());
        $this->assertSame(LicenseStatus::SUSPENDED, $expiredSuspended->fresh()->getOriginStatusBeforeExpiration());
        $this->assertSame(LicenseStatus::UNUSED, $expiredUnused->fresh()->getOriginStatusBeforeExpiration());

        // Test Renewal on synced expired UNUSED -> returns UNUSED
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $expiredUnused), [
                'expires_at' => now()->addYear()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(LicenseStatus::UNUSED, $expiredUnused->fresh()->status);

        // Test Renewal on synced expired SUSPENDED -> returns ACTIVE with activation intact
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $expiredSuspended), [
                'expires_at' => now()->addYear()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(LicenseStatus::ACTIVE, $expiredSuspended->fresh()->status);
        $this->assertDatabaseHas('activations', ['license_id' => $expiredSuspended->id]);

        // Test Reset on synced expired ACTIVE -> rejected
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.reset-binding', $expiredActive))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
