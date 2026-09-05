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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@license.katresnanku.com',
            'password' => bcrypt('StrongPassword123!'),
        ]);
    }

    /**
     * Guest is redirected to login when accessing dashboard.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/admin/dashboard');
        $response->assertRedirect('/login');
    }

    /**
     * Admin dashboard renders accurate system metrics and license breakdown.
     */
    public function test_admin_dashboard_displays_correct_metrics(): void
    {
        $app1 = Application::create([
            'code' => 'APP-ALPHA',
            'name' => 'Alpha App',
            'is_active' => true,
        ]);

        $app2 = Application::create([
            'code' => 'APP-BETA',
            'name' => 'Beta App',
            'is_active' => false,
        ]);

        ApplicationApiKey::create([
            'application_id' => $app1->id,
            'key_id' => 'ak_activekey12345678901234',
            'key_hash' => hash('sha256', 'sec_123456789012345678901234567890123456789012345678'),
            'name' => 'Active Key',
            'status' => ApplicationApiKeyStatus::ACTIVE,
        ]);

        ApplicationApiKey::create([
            'application_id' => $app1->id,
            'key_id' => 'ak_revokedkey1234567890123',
            'key_hash' => hash('sha256', 'sec_revoked12345678901234567890123456789012345678901'),
            'name' => 'Revoked Key',
            'status' => ApplicationApiKeyStatus::REVOKED,
        ]);

        $lic1 = License::create([
            'application_id' => $app1->id,
            'key_hash' => hash('sha256', 'APP-ALPHA-1111-2222-3333-4444-AA'),
            'key_masked' => 'APP-ALPHA-1111-****-****-4444-AA',
            'status' => LicenseStatus::ACTIVE,
            'customer_name' => 'John Doe',
        ]);

        License::create([
            'application_id' => $app1->id,
            'key_hash' => hash('sha256', 'APP-ALPHA-5555-6666-7777-8888-BB'),
            'key_masked' => 'APP-ALPHA-5555-****-****-8888-BB',
            'status' => LicenseStatus::SUSPENDED,
            'customer_name' => 'Jane Smith',
        ]);

        Activation::create([
            'license_id' => $lic1->id,
            'canonical_domain' => 'alpha.example.com',
            'ip_address' => '1.2.3.4',
            'token_id' => 'tok_1234567890',
            'token_expires_at' => now('UTC')->addDays(30),
            'activated_at' => now('UTC'),
            'last_verified_at' => now('UTC'),
        ]);

        LicenseLog::create([
            'application_id' => $app1->id,
            'license_id' => $lic1->id,
            'event' => LicenseLogEvent::ACTIVATION_SUCCESS,
            'actor_type' => LicenseLogActorType::CLIENT,
            'ip_address' => '1.2.3.4',
            'payload' => ['domain' => 'alpha.example.com'],
            'created_at' => now('UTC'),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('totalApplications', 2);
        $response->assertViewHas('activeApplications', 1);
        $response->assertViewHas('activeApiKeys', 1);
        $response->assertViewHas('totalLicenses', 2);
        $response->assertViewHas('activeLicenses', 1);
        $response->assertViewHas('suspendedLicenses', 1);
        $response->assertViewHas('totalActivations', 1);
        $response->assertSee('APP-ALPHA');
        $response->assertSee('ACTIVATION_SUCCESS');
    }
}
