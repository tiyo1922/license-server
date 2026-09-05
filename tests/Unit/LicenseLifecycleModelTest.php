<?php

namespace Tests\Unit;

use App\Enums\LicenseStatus;
use App\Models\License;
use Carbon\Carbon;
use Tests\TestCase;

class LicenseLifecycleModelTest extends TestCase
{
    public function test_lifetime_license_never_authoritatively_expires(): void
    {
        $license = new License([
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => null,
        ]);

        $this->assertTrue($license->isLifetime());
        $this->assertFalse($license->isTimeBound());
        $this->assertFalse($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::ACTIVE, $license->getAuthoritativeStatus());
    }

    public function test_unexpired_time_bound_license_resolves_to_persisted_status(): void
    {
        $future = Carbon::now('UTC')->addDay();
        $license = new License([
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => $future,
        ]);

        $this->assertFalse($license->isLifetime());
        $this->assertTrue($license->isTimeBound());
        $this->assertFalse($license->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::ACTIVE, $license->getAuthoritativeStatus());
        $this->assertTrue($license->isActive());
        $this->assertFalse($license->isExpired());
    }

    public function test_expired_time_bound_license_resolves_to_expired_authoritatively(): void
    {
        $past = Carbon::now('UTC')->subMinute();

        // Stale ACTIVE
        $activeLicense = new License([
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => $past,
        ]);
        $this->assertTrue($activeLicense->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $activeLicense->getAuthoritativeStatus());
        $this->assertTrue($activeLicense->isExpired());
        $this->assertFalse($activeLicense->isActive());

        // Stale SUSPENDED
        $suspendedLicense = new License([
            'status' => LicenseStatus::SUSPENDED,
            'expires_at' => $past,
        ]);
        $this->assertTrue($suspendedLicense->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $suspendedLicense->getAuthoritativeStatus());
        $this->assertTrue($suspendedLicense->isExpired());
        $this->assertFalse($suspendedLicense->isSuspended());

        // Stale UNUSED
        $unusedLicense = new License([
            'status' => LicenseStatus::UNUSED,
            'expires_at' => $past,
        ]);
        $this->assertTrue($unusedLicense->isAuthoritativelyExpired());
        $this->assertSame(LicenseStatus::EXPIRED, $unusedLicense->getAuthoritativeStatus());
        $this->assertTrue($unusedLicense->isExpired());
        $this->assertFalse($unusedLicense->isUnused());
    }

    public function test_revoked_status_is_strictly_terminal_even_if_expired(): void
    {
        $past = Carbon::now('UTC')->subDay();
        $revokedLicense = new License([
            'status' => LicenseStatus::REVOKED,
            'expires_at' => $past,
        ]);

        $this->assertSame(LicenseStatus::REVOKED, $revokedLicense->getAuthoritativeStatus());
        $this->assertTrue($revokedLicense->isRevoked());
        $this->assertFalse($revokedLicense->isExpired());
    }
}
