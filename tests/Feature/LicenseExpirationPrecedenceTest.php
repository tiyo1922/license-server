<?php

namespace Tests\Feature;

use App\Enums\LicenseStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Application;
use App\Models\License;
use App\Models\User;
use App\Services\License\LicenseLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseExpirationPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Application $application;
    private LicenseLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->application = Application::create([
            'name' => 'Sumber Protein Jogja',
            'code' => 'SPJ',
            'is_active' => true,
        ]);
        $this->lifecycleService = app(LicenseLifecycleService::class);
    }

    public function test_stale_active_license_is_authoritatively_expired(): void
    {
        $past = Carbon::now('UTC')->subMinutes(5);
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-STALE-ACTIVE'),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => $past,
        ]);

        $this->assertTrue($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $license->getAuthoritativeStatus());

        // Attempting to suspend should be rejected because authoritative state is EXPIRED
        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage('Cannot suspend an expired license.');

        $this->lifecycleService->suspend($license, 'Trying to suspend expired active license');
    }

    public function test_stale_suspended_license_cannot_be_unsuspended(): void
    {
        $past = Carbon::now('UTC')->subHours(2);
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-STALE-SUSPENDED'),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => LicenseStatus::SUSPENDED,
            'expires_at' => $past,
        ]);

        $this->assertTrue($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $license->getAuthoritativeStatus());

        // Attempting to unsuspend should be rejected
        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage('Cannot unsuspend an expired license. Renewal is required.');

        $this->lifecycleService->unsuspend($license, 'Trying to unsuspend expired license');
    }

    public function test_stale_unused_license_resolves_to_authoritative_expired(): void
    {
        $past = Carbon::now('UTC')->subDay();
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-STALE-UNUSED'),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => LicenseStatus::UNUSED,
            'expires_at' => $past,
        ]);

        $this->assertTrue($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $license->getAuthoritativeStatus());
    }

    public function test_lifetime_license_is_never_expired(): void
    {
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'KEY-LIFETIME'),
            'key_masked' => 'SPJ-****-****-****-****-0000',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $this->assertFalse($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::ACTIVE, $license->getAuthoritativeStatus());

        // Suspending lifetime active license succeeds
        $suspended = $this->lifecycleService->suspend($license, 'Suspending lifetime license', $this->admin->id);
        $this->assertSame(LicenseStatus::SUSPENDED, $suspended->status);
    }
}
