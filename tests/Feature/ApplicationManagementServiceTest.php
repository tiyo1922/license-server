<?php

namespace Tests\Feature;

use App\Enums\LicenseLogActorType;
use App\Enums\LicenseLogEvent;
use App\Models\Application;
use App\Models\LicenseLog;
use App\Models\User;
use App\Services\License\ApplicationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApplicationManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ApplicationManagementService $service;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApplicationManagementService();
        $this->adminUser = User::factory()->create(['email' => 'admin@example.com']);
    }

    /**
     * 1. Create application persists model correctly.
     */
    public function test_creates_application_successfully(): void
    {
        $app = $this->service->createApplication([
            'code' => 'WIDGET-PRO',
            'name' => 'Widget Professional Suite',
            'description' => 'Main enterprise plugin',
        ], $this->adminUser->id);

        $this->assertDatabaseHas('applications', [
            'id' => $app->id,
            'code' => 'WIDGET-PRO',
            'name' => 'Widget Professional Suite',
            'description' => 'Main enterprise plugin',
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'license_id' => null,
            'event' => LicenseLogEvent::APPLICATION_CREATED->value,
            'actor_type' => LicenseLogActorType::ADMIN->value,
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * 2. Duplicate application code is rejected.
     */
    public function test_rejects_duplicate_application_code(): void
    {
        $this->service->createApplication([
            'code' => 'APP-DUP',
            'name' => 'First App',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application code [APP-DUP] is already registered.');

        $this->service->createApplication([
            'code' => 'app-dup', // Lowercase should normalize and collide
            'name' => 'Second App',
        ]);
    }

    /**
     * 3. Code normalization & validation rules.
     */
    public function test_validates_and_normalizes_application_code(): void
    {
        // Lowercase is normalized to uppercase
        $app = $this->service->createApplication([
            'code' => '   sumber_protein-app   ',
            'name' => 'Normalized App',
        ]);

        $this->assertSame('SUMBER_PROTEIN-APP', $app->code);

        // Invalid characters rejected
        $this->expectException(InvalidArgumentException::class);
        $this->service->createApplication([
            'code' => 'INVALID CODE WITH SPACES!',
            'name' => 'Invalid Code App',
        ]);
    }

    /**
     * 4. Application code is strictly immutable on update.
     */
    public function test_application_code_is_strictly_immutable(): void
    {
        $app = $this->service->createApplication([
            'code' => 'LOCKED-CODE',
            'name' => 'Original Name',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application code is strictly immutable and cannot be modified after creation.');

        $this->service->updateApplication($app, [
            'code' => 'MUTATED-CODE',
            'name' => 'Updated Name',
        ]);
    }

    /**
     * 5. Metadata update (name, description) succeeds and is audited.
     */
    public function test_updates_application_metadata_successfully(): void
    {
        $app = $this->service->createApplication([
            'code' => 'META-APP',
            'name' => 'Initial Title',
            'description' => 'Initial Desc',
        ]);

        $updated = $this->service->updateApplication($app, [
            'name' => 'Revised Title',
            'description' => 'Revised Desc',
        ], $this->adminUser->id);

        $this->assertSame('Revised Title', $updated->name);
        $this->assertSame('Revised Desc', $updated->description);
        $this->assertSame('META-APP', $updated->code);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'event' => LicenseLogEvent::APPLICATION_UPDATED->value,
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * 6, 7, 8. Enable / Disable lifecycle toggling and audit events.
     */
    public function test_toggles_application_active_state(): void
    {
        $app = $this->service->createApplication([
            'code' => 'TOGGLE-APP',
            'name' => 'Toggle Application',
        ]);

        $this->assertTrue($app->is_active);

        // Disable
        $disabled = $this->service->disableApplication($app, 'Temporary maintenance', $this->adminUser->id);
        $this->assertFalse($disabled->is_active);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'event' => LicenseLogEvent::APPLICATION_TOGGLED->value,
            'payload->is_active' => false,
            'payload->previous_is_active' => true,
            'payload->reason' => 'Temporary maintenance',
        ]);

        // Enable
        $enabled = $this->service->enableApplication($app, 'Maintenance completed', $this->adminUser->id);
        $this->assertTrue($enabled->is_active);

        $this->assertDatabaseHas('license_logs', [
            'application_id' => $app->id,
            'event' => LicenseLogEvent::APPLICATION_TOGGLED->value,
            'payload->is_active' => true,
            'payload->previous_is_active' => false,
            'payload->reason' => 'Maintenance completed',
        ]);
    }
}
