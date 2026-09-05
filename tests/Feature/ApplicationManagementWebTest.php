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
            'code' => 'GUEST-TEST',
            'name' => 'Guest Test App',
            'is_active' => true,
        ]);

        $this->get('/admin/applications')->assertRedirect('/login');
        $this->get('/admin/applications/create')->assertRedirect('/login');
        $this->post('/admin/applications', ['code' => 'NEW', 'name' => 'New'])->assertRedirect('/login');
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
        Application::create(['code' => 'APP-ALPHA', 'name' => 'Alpha App', 'is_active' => true]);
        Application::create(['code' => 'APP-BETA', 'name' => 'Beta App', 'is_active' => false]);

        $response = $this->actingAs($this->adminUser)->get('/admin/applications');

        $response->assertOk()
            ->assertSee('Application Management')
            ->assertSee('APP-ALPHA')
            ->assertSee('Alpha App')
            ->assertSee('APP-BETA')
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
     * Admin can store a new application.
     */
    public function test_admin_can_store_new_application(): void
    {
        $response = $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'prot-suite',
            'name' => 'Protein Management Suite',
            'description' => 'Main client application for protein delivery management',
            'is_active' => '1',
        ]);

        $app = Application::where('code', 'PROT-SUITE')->first();
        $this->assertNotNull($app);

        $response->assertRedirect("/admin/applications/{$app->id}")
            ->assertSessionHas('success');

        $this->assertSame('PROT-SUITE', $app->code);
        $this->assertSame('Protein Management Suite', $app->name);
        $this->assertTrue($app->is_active);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'event' => LicenseLogEvent::APPLICATION_CREATED->value,
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * Store validation rejects invalid code or duplicate.
     */
    public function test_store_validation_rejects_duplicates_and_invalid_codes(): void
    {
        Application::create(['code' => 'EXISTING', 'name' => 'Existing App']);

        // Duplicate code
        $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'EXISTING',
            'name' => 'Duplicate App',
        ])->assertSessionHasErrors(['code']);

        // Malformed code with spaces
        $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'BAD CODE WITH SPACES',
            'name' => 'Bad App',
        ])->assertSessionHasErrors(['code']);

        // Missing name
        $this->actingAs($this->adminUser)->post('/admin/applications', [
            'code' => 'VALID-CODE',
            'name' => '',
        ])->assertSessionHasErrors(['name']);
    }

    /**
     * Admin can view application details page.
     */
    public function test_admin_can_view_application_details(): void
    {
        $app = Application::create([
            'code' => 'DETAIL-APP',
            'name' => 'Detail Test App',
            'description' => 'A detailed application',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)->get("/admin/applications/{$app->id}");

        $response->assertOk()
            ->assertSee('DETAIL-APP')
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
            'code' => 'STATIC-CODE',
            'name' => 'Old Title',
            'description' => 'Old Desc',
        ]);

        $response = $this->actingAs($this->adminUser)->put("/admin/applications/{$app->id}", [
            'name' => 'Brand New Title',
            'description' => 'Updated Description',
        ]);

        $response->assertRedirect("/admin/applications/{$app->id}")
            ->assertSessionHas('success');

        $app->refresh();
        $this->assertSame('Brand New Title', $app->name);
        $this->assertSame('Updated Description', $app->description);
        $this->assertSame('STATIC-CODE', $app->code); // Immutability preserved
    }

    /**
     * Admin can toggle application active status.
     */
    public function test_admin_can_toggle_application_active_status(): void
    {
        $app = Application::create([
            'code' => 'STATUS-APP',
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
