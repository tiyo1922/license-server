<?php

namespace Tests\Feature;

use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationManagementWebTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->create(['email' => 'admin@example.com']);
    }

    /**
     * Guest is redirected to login for all application admin routes.
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $app = Application::create([
            'code' => 'GST01',
            'name' => 'Guest Test App',
            'is_active' => true,
        ]);

        $this->get('/admin/applications')->assertRedirect('/login');
        $this->get('/admin/applications/create')->assertRedirect('/login');
        $this->post('/admin/applications', ['code' => 'NEW01', 'name' => 'New'])->assertRedirect('/login');
        $this->get("/admin/applications/{$app->id}")->assertRedirect('/login');
        $this->get("/admin/applications/{$app->id}/edit")->assertRedirect('/login');
        $this->put("/admin/applications/{$app->id}", ['name' => 'Updated'])->assertRedirect('/login');
        $this->post("/admin/applications/{$app->id}/toggle-active")->assertRedirect('/login');
    }

    /**
     * Admin can view applications index list.
     */
    public function test_admin_can_view_applications_index(): void
    {
        Application::create(['code' => 'APPA', 'name' => 'Alpha App', 'is_active' => true]);
        Application::create(['code' => 'APPB', 'name' => 'Beta App', 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)->get('/admin/applications');

        $response->assertOk()
            ->assertSee('Application Management')
            ->assertSee('APPA')
            ->assertSee('Alpha App')
            ->assertSee('APPB')
            ->assertSee('Beta App')
            ->assertSee('ACTIVE')
            ->assertSee('INACTIVE');
    }

    /**
     * Admin can view create form.
     */
    public function test_admin_can_view_create_application_form(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/applications/create');

        $response->assertOk()
            ->assertSee('Register Application')
            ->assertSee('Application Code (Identifier)')
            ->assertSee('Application Name');
    }

    /**
     * Admin can store a new application with valid codes (2-5 uppercase alphanumeric chars).
     */
    public function test_admin_can_store_new_application_with_valid_codes(): void
    {
        // 1. Valid 5-char alphanumeric (e.g. SPJ22)
        $response1 = $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'SPJ22',
            'name' => 'Sumber Protein Jogja - Production',
            'description' => 'Main client application for protein delivery management',
            'is_active' => '1',
        ]);

        $app1 = Application::where('code', 'SPJ22')->first();
        $this->assertNotNull($app1);
        $response1->assertRedirect("/admin/applications/{$app1->id}")
            ->assertSessionHas('success');
        $this->assertSame('SPJ22', $app1->code);
        $this->assertSame('Sumber Protein Jogja - Production', $app1->name);
        $this->assertTrue($app1->is_active);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app1->id,
            'event' => LicenseLogEvent::APPLICATION_CREATED->value,
            'actor_user_id' => $this->adminUser->id,
        ]);

        // 2. Valid 2-char code (e.g. SP)
        $response2 = $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'SP',
            'name' => 'Sumber Protein Minimal',
            'is_active' => '1',
        ]);
        $app2 = Application::where('code', 'SP')->first();
        $this->assertNotNull($app2);
        $response2->assertRedirect("/admin/applications/{$app2->id}")
            ->assertSessionHas('success');

        // 3. Valid uppercase alphanumeric 5-char (e.g. ABC12)
        $response3 = $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'ABC12',
            'name' => 'Alpha Beta Client 12',
            'is_active' => '1',
        ]);
        $app3 = Application::where('code', 'ABC12')->first();
        $this->assertNotNull($app3);
        $response3->assertRedirect("/admin/applications/{$app3->id}")
            ->assertSessionHas('success');
    }

    /**
     * Store validation rejects invalid codes (1 char, >5 chars, lowercase, hyphens, spaces, special chars, SUMBER-PROT-1922) and duplicates.
     */
    public function test_store_validation_rejects_invalid_codes_and_duplicates(): void
    {
        Application::create(['code' => 'EXIST', 'name' => 'Existing App']);

        $invalidCodes = [
            'S',                    // 1 char
            'A',                    // 1 char
            'ABCDEF',               // > 5 chars (6 chars)
            '123456',               // > 5 chars (6 digits)
            'SUMBER-PROT-1922',     // > 5 chars and hyphens
            'SP-JO',                // contains hyphen
            'spjo',                 // lowercase
            'SP JO',                // contains whitespace
            'SP@1',                 // contains special character
            'SP#J',                 // contains special character
            'EXIST',                // duplicate code
        ];

        foreach ($invalidCodes as $invalidCode) {
            $response = $this->actingAs($this->adminUser)->post('/admin/applications', [
                'code' => $invalidCode,
                'name' => 'Test App with Invalid Code',
            ]);

            $response->assertSessionHasErrors(['code']);

            // Ensure no application was created for the invalid input
            if ($invalidCode !== 'EXIST') {
                $this->assertDatabaseMissing('applications', [
                    'code' => strtoupper($invalidCode),
                ]);
            }
        }

        // Missing name
        $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'VALID',
            'name' => '',
        ])->assertSessionHasErrors(['name']);

        $this->assertDatabaseMissing('applications', [
            'code' => 'VALID',
        ]);
    }

    /**
     * Admin can view application details page.
     */
    public function test_admin_can_view_application_details(): void
    {
        $app = Application::create([
            'code' => 'DTL01',
            'name' => 'Detail Test App',
            'description' => 'A detailed application',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)->get("/admin/applications/{$app->id}");

        $response->assertOk()
            ->assertSee('DTL01')
            ->assertSee('Detail Test App')
            ->assertSee('A detailed application')
            ->assertSee('Application API Keys')
            ->assertSee('Generate New API Key');
    }

    /**
     * Admin can update application metadata while code remains immutable.
     */
    public function test_admin_can_update_application_metadata(): void
    {
        $app = Application::create([
            'code' => 'STAT1',
            'name' => 'Old Title',
            'description' => 'Old Desc',
        ]);

        $response = $this->actingAs($this->adminUser)->put("/admin/applications/{$app->id}", [
            'name' => 'Brand New Title',
            'description' => 'Updated Description',
            'code' => 'MUTAT', // Attempt to mutate code must be ignored/immutable
        ]);

        $response->assertRedirect("/admin/applications/{$app->id}")
            ->assertSessionHas('success');

        $app->refresh();
        $this->assertSame('Brand New Title', $app->name);
        $this->assertSame('Updated Description', $app->description);
        $this->assertSame('STAT1', $app->code); // Immutability preserved
    }

    /**
     * Admin can toggle application active status.
     */
    public function test_admin_can_toggle_application_active_status(): void
    {
        $app = Application::create([
            'code' => 'STAT2',
            'name' => 'Status App',
            'is_active' => true,
        ]);

        // Toggle to inactive
        $this->actingAs($this->adminUser)
            ->post("/admin/applications/{$app->id}/toggle-active", ['reason' => 'Emergency maintenance'])
            ->assertRedirect("/admin/applications/{$app->id}")
            ->assertSessionHas('success');

        $app->refresh();
        $this->assertFalse($app->is_active);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'event' => LicenseLogEvent::APPLICATION_TOGGLED->value,
            'payload->is_active' => false,
            'payload->previous_is_active' => true,
            'payload->reason' => 'Emergency maintenance',
        ]);

        // Toggle back to active
        $this->actingAs($this->adminUser)
            ->post("/admin/applications/{$app->id}/toggle-active")
            ->assertRedirect("/admin/applications/{$app->id}");

        $app->refresh();
        $this->assertTrue($app->is_active);
    }
}
