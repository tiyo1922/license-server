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
use App\Services\License\LicenseLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseLifecycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Application $application;

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

    private function createLicense(LicenseStatus $status = LicenseStatus::ACTIVE, ?Carbon $expiresAt = null): License
    {
        return License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-' . uniqid()),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => $status,
            'expires_at' => $expiresAt,
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

    // --- SUSPEND TESTS ---

    public function test_admin_can_suspend_active_unexpired_license(): void
    {
        $license = $this->createLicense(LicenseStatus::ACTIVE, now()->addYear());
        $activation = $this->createActivation($license, 'app.sumberprotein.com');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.licenses.suspend', $license), [
                'reason' => 'Payment investigation',
            ]);

        $response->assertRedirect(route('admin.licenses.show', $license));
        $response->assertSessionHas('success', 'License suspended successfully.');

        $license->refresh();
        $this->assertSame(LicenseStatus::SUSPENDED, $license->status);

        // Activation record must be preserved
        $this->assertDatabaseHas('activations', [
            'id' => $activation->id,
            'license_id' => $license->id,
        ]);

        // Audit log
        $this->assertDatabaseHas('license_logs', [
            'license_id' => $license->id,
            'event' => LicenseLogEvent::LICENSE_SUSPENDED->value,
            'actor_type' => LicenseLogActorType::ADMIN->value,
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_cannot_suspend_unused_or_revoked_or_expired_license(): void
    {
        // UNUSED
        $unused = $this->createLicense(LicenseStatus::UNUSED);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.suspend', $unused))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(LicenseStatus::UNUSED, $unused->fresh()->status);

        // REVOKED
        $revoked = $this->createLicense(LicenseStatus::REVOKED);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.suspend', $revoked))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(LicenseStatus::REVOKED, $revoked->fresh()->status);

        // EXPIRED
        $expired = $this->createLicense(LicenseStatus::ACTIVE, now()->subDay());
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.suspend', $expired))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // --- UNSUSPEND TESTS ---

    public function test_admin_can_unsuspend_suspended_unexpired_license(): void
    {
        $license = $this->createLicense(LicenseStatus::SUSPENDED, now()->addYear());
        $activation = $this->createActivation($license);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.licenses.unsuspend', $license), [
                'reason' => 'Payment cleared',
            ]);

        $response->assertRedirect(route('admin.licenses.show', $license));
        $response->assertSessionHas('success', 'License unsuspended successfully.');

        $license->refresh();
        $this->assertSame(LicenseStatus::ACTIVE, $license->status);

        // Activation record remains intact
        $this->assertDatabaseHas('activations', [
            'id' => $activation->id,
            'license_id' => $license->id,
        ]);

        $this->assertDatabaseHas('license_logs', [
            'license_id' => $license->id,
            'event' => LicenseLogEvent::LICENSE_UNSUSPENDED->value,
            'actor_type' => LicenseLogActorType::ADMIN->value,
        ]);
    }

    public function test_cannot_unsuspend_expired_suspended_license(): void
    {
        $license = $this->createLicense(LicenseStatus::SUSPENDED, now()->subDay());

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.unsuspend', $license))
            ->assertRedirect()
            ->assertSessionHas('error', 'Cannot unsuspend an expired license. Renewal is required.');

        $this->assertSame(LicenseStatus::SUSPENDED, $license->fresh()->status);
    }

    // --- RESET BINDING TESTS ---

    public function test_admin_can_reset_binding_for_suspended_license(): void
    {
        $license = $this->createLicense(LicenseStatus::SUSPENDED);
        $activation = $this->createActivation($license, 'old.sumberprotein.com');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.licenses.reset-binding', $license), [
                'reason' => 'Server migration requested by customer',
            ]);

        $response->assertRedirect(route('admin.licenses.show', $license));
        $response->assertSessionHas('success');

        $license->refresh();
        $this->assertSame(LicenseStatus::UNUSED, $license->status);

        // Activation record must be deleted
        $this->assertDatabaseMissing('activations', [
            'id' => $activation->id,
        ]);

        // Audit log
        $this->assertDatabaseHas('license_logs', [
            'license_id' => $license->id,
            'event' => LicenseLogEvent::BINDING_RESET->value,
            'actor_type' => LicenseLogActorType::ADMIN->value,
        ]);
    }

    public function test_cannot_reset_binding_directly_from_active_status(): void
    {
        $license = $this->createLicense(LicenseStatus::ACTIVE);
        $this->createActivation($license);

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.reset-binding', $license))
            ->assertRedirect()
            ->assertSessionHas('error', 'Cannot reset binding directly from ACTIVE status. The license must be suspended first.');

        $this->assertSame(LicenseStatus::ACTIVE, $license->fresh()->status);
        $this->assertDatabaseCount('activations', 1);
    }

    public function test_admin_can_reset_binding_for_expired_suspended_license(): void
    {
        $license = $this->createLicense(LicenseStatus::SUSPENDED, now()->subDay());
        $activation = $this->createActivation($license, 'expired-suspended.test');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.licenses.reset-binding', $license));

        $response->assertRedirect(route('admin.licenses.show', $license));
        $response->assertSessionHas('success');

        $this->assertSame(LicenseStatus::UNUSED, $license->fresh()->status);
        $this->assertDatabaseMissing('activations', ['id' => $activation->id]);
    }

    public function test_cannot_reset_binding_for_expired_active_license(): void
    {
        $license = $this->createLicense(LicenseStatus::ACTIVE, now()->subDay());
        $this->createActivation($license, 'expired-active.test');

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.reset-binding', $license))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('activations', 1);
    }

    // --- REVOKE TESTS ---

    public function test_admin_can_revoke_active_suspended_or_expired_license(): void
    {
        // 1. Revoke ACTIVE
        $activeLicense = $this->createLicense(LicenseStatus::ACTIVE);
        $this->createActivation($activeLicense);

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.revoke', $activeLicense), ['reason' => 'Fraud detected'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LicenseStatus::REVOKED, $activeLicense->fresh()->status);
        $this->assertDatabaseMissing('activations', ['license_id' => $activeLicense->id]);

        // 2. Revoke SUSPENDED
        $suspendedLicense = $this->createLicense(LicenseStatus::SUSPENDED);
        $this->createActivation($suspendedLicense);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.revoke', $suspendedLicense))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(LicenseStatus::REVOKED, $suspendedLicense->fresh()->status);
        $this->assertDatabaseMissing('activations', ['license_id' => $suspendedLicense->id]);

        // 3. Revoke EXPIRED (with prior activation)
        $expiredLicense = $this->createLicense(LicenseStatus::EXPIRED, now()->subDay());
        $this->createActivation($expiredLicense);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.revoke', $expiredLicense))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(LicenseStatus::REVOKED, $expiredLicense->fresh()->status);
        $this->assertDatabaseMissing('activations', ['license_id' => $expiredLicense->id]);
    }

    public function test_cannot_revoke_unused_or_already_revoked_license(): void
    {
        $unused = $this->createLicense(LicenseStatus::UNUSED);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.revoke', $unused))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(LicenseStatus::UNUSED, $unused->fresh()->status);

        $revoked = $this->createLicense(LicenseStatus::REVOKED);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.revoke', $revoked))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    // --- RENEWAL TESTS ---

    public function test_admin_can_renew_expired_license_to_active(): void
    {
        $license = $this->createLicense(LicenseStatus::ACTIVE, now()->subDay());
        $activation = $this->createActivation($license);
        $newExpiry = now()->addYear()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $license), [
                'expires_at' => $newExpiry,
                'reason' => 'Annual subscription renewal',
            ]);

        $response->assertRedirect(route('admin.licenses.show', $license));
        $response->assertSessionHas('success');

        $license->refresh();
        $this->assertSame(LicenseStatus::ACTIVE, $license->status);
        $this->assertNotNull($license->expires_at);
        $this->assertTrue($license->expires_at->isFuture());

        // Activation preserved
        $this->assertDatabaseHas('activations', [
            'id' => $activation->id,
            'license_id' => $license->id,
        ]);

        // Audit log
        $this->assertDatabaseHas('license_logs', [
            'license_id' => $license->id,
            'event' => LicenseLogEvent::LICENSE_RENEWED->value,
            'actor_type' => LicenseLogActorType::ADMIN->value,
        ]);
    }

    public function test_renewal_of_expired_unused_license_remains_unused(): void
    {
        $unusedExpired = $this->createLicense(LicenseStatus::UNUSED, now()->subDay());
        $newExpiry = now()->addYear()->format('Y-m-d H:i:s');

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $unusedExpired), [
                'expires_at' => $newExpiry,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $unusedExpired->refresh();
        $this->assertSame(LicenseStatus::UNUSED, $unusedExpired->status);
        $this->assertTrue($unusedExpired->expires_at->isFuture());
        $this->assertNull($unusedExpired->activation);
    }

    public function test_renewal_of_expired_suspended_license_becomes_active_with_binding_preserved(): void
    {
        $suspendedExpired = $this->createLicense(LicenseStatus::SUSPENDED, now()->subDay());
        $activation = $this->createActivation($suspendedExpired, 'retained.test');
        $newExpiry = now()->addYear()->format('Y-m-d H:i:s');

        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $suspendedExpired), [
                'expires_at' => $newExpiry,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $suspendedExpired->refresh();
        $this->assertSame(LicenseStatus::ACTIVE, $suspendedExpired->status);
        $this->assertTrue($suspendedExpired->expires_at->isFuture());
        $this->assertDatabaseHas('activations', [
            'id' => $activation->id,
            'license_id' => $suspendedExpired->id,
        ]);
    }

    public function test_cannot_renew_revoked_license_or_pass_past_date(): void
    {
        $revoked = $this->createLicense(LicenseStatus::REVOKED);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $revoked), [
                'expires_at' => now()->addYear()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Cannot renew a permanently revoked license.');

        $active = $this->createLicense(LicenseStatus::ACTIVE);
        $this->actingAs($this->admin)
            ->post(route('admin.licenses.renew', $active), [
                'expires_at' => now()->subDay()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('expires_at');
    }

    // --- ATOMICITY TESTS ---

    public function test_lifecycle_transaction_rolls_back_if_audit_fails(): void
    {
        $license = $this->createLicense(LicenseStatus::ACTIVE, now()->addYear());
        $activation = $this->createActivation($license);

        // Intercept LicenseLog::creating to simulate audit logging failure
        LicenseLog::saving(function () {
            throw new \RuntimeException('Simulated audit logging failure');
        });

        $service = app(LicenseLifecycleService::class);

        $thrown = false;
        try {
            $service->suspend($license, 'Test reason', $this->admin->id);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Simulated audit logging failure', $e->getMessage());
        }

        $this->assertTrue($thrown);

        // State must remain ACTIVE, activation intact, no partial mutation
        $license->refresh();
        $this->assertSame(LicenseStatus::ACTIVE, $license->status);
        $this->assertDatabaseHas('activations', ['id' => $activation->id]);
        $this->assertDatabaseCount('license_logs', 0);
    }

    // --- AUTHORIZATION & CSRF TESTS ---

    public function test_guest_is_redirected_to_login_on_all_lifecycle_actions(): void
    {
        $license = $this->createLicense();

        $this->post(route('admin.licenses.suspend', $license))->assertRedirect('/login');
        $this->post(route('admin.licenses.unsuspend', $license))->assertRedirect('/login');
        $this->post(route('admin.licenses.revoke', $license))->assertRedirect('/login');
        $this->post(route('admin.licenses.reset-binding', $license))->assertRedirect('/login');
        $this->post(route('admin.licenses.renew', $license), ['expires_at' => now()->addYear()])->assertRedirect('/login');
    }
}
