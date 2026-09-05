<?php

namespace Tests\Feature;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\License;
use App\Models\LicenseLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@license.katresnanku.com',
            'password' => bcrypt('StrongPassword123!'),
        ]);

        $this->application = Application::create([
            'code' => 'AUDIT-TEST-APP',
            'name' => 'Audit Test App',
            'is_active' => true,
        ]);
    }

    /**
     * Guest is redirected to login when accessing audit logs.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/admin/audit-logs');
        $response->assertRedirect('/login');
    }

    /**
     * Admin can view audit logs and filter by event type.
     */
    public function test_admin_can_filter_audit_logs_by_event(): void
    {
        LicenseLog::create([
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::APPLICATION_CREATED,
            'actor_type' => LicenseLogActorType::ADMIN,
            'actor_user_id' => $this->admin->id,
            'ip_address' => '127.0.0.1',
            'payload' => ['code' => 'AUDIT-TEST-APP'],
            'created_at' => now('UTC'),
        ]);

        LicenseLog::create([
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::API_KEY_CREATED,
            'actor_type' => LicenseLogActorType::ADMIN,
            'actor_user_id' => $this->admin->id,
            'ip_address' => '127.0.0.1',
            'payload' => ['key_id' => 'ak_test123456789012345678'],
            'created_at' => now('UTC'),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/audit-logs?event=APPLICATION_CREATED');

        $response->assertStatus(200);
        $response->assertViewHas('logs', function ($logs) {
            return $logs->count() === 1 && $logs->first()->event === LicenseLogEvent::APPLICATION_CREATED;
        });
    }

    /**
     * Admin can filter audit logs by application.
     */
    public function test_admin_can_filter_audit_logs_by_application(): void
    {
        $otherApp = Application::create([
            'code' => 'OTHER-APP',
            'name' => 'Other App',
            'is_active' => true,
        ]);

        LicenseLog::create([
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::APPLICATION_CREATED,
            'actor_type' => LicenseLogActorType::ADMIN,
            'created_at' => now('UTC'),
        ]);

        LicenseLog::create([
            'application_id' => $otherApp->id,
            'event' => LicenseLogEvent::APPLICATION_CREATED,
            'actor_type' => LicenseLogActorType::ADMIN,
            'created_at' => now('UTC'),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/audit-logs?application_id=' . $this->application->id);

        $response->assertStatus(200);
        $response->assertViewHas('logs', function ($logs) {
            return $logs->count() === 1 && $logs->first()->application_id === $this->application->id;
        });
    }

    /**
     * Audit log payloads are safely escaped and never leak sensitive raw credentials.
     */
    public function test_audit_log_payloads_rendered_safely(): void
    {
        LicenseLog::create([
            'application_id' => $this->application->id,
            'event' => LicenseLogEvent::API_KEY_CREATED,
            'actor_type' => LicenseLogActorType::ADMIN,
            'actor_user_id' => $this->admin->id,
            'actor_key_id' => 'ak_safe_identifier_123456',
            'ip_address' => '10.0.0.1',
            'payload' => [
                'key_id' => 'ak_safe_identifier_123456',
                'name' => 'Safe Key Name <script>alert(1)</script>',
            ],
            'created_at' => now('UTC'),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/audit-logs');

        $response->assertStatus(200);
        // The script tag is HTML-escaped
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('Safe Key Name');
    }
}
