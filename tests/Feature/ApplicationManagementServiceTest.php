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
            'code' => 'WID01',
            'name' => 'Widget Professional Suite',
            'description' => 'Main enterprise plugin',
        ], $this->adminUser->id);

        $this->assertDatabaseHas('applications', [
            'id' => $app->id,
            'code' => 'WID01',
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
            'code' => 'APP01',
            'name' => 'First App',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application code [APP01] is already registered.');

        $this->service->createApplication([
            'code' => 'app01', // Lowercase should normalize and collide
            'name' => 'Second App',
        ]);
    }

    /**
     * 3. Code normalization & validation rules (2-5 uppercase alphanumeric characters).
     */
    public function test_validates_and_normalizes_application_code(): void
    {
        // Valid 2-character code
        $app2 = $this->service->createApplication([
            'code' => 'SP',
            'name' => '2 Char App',
        ]);
        $this->assertSame('SP', $app2->code);

        // Valid 5-character alphanumeric code with whitespace normalization
        $app5 = $this->service->createApplication([
            'code' => '  spj22  ',
            'name' => '5 Char Normalized App',
        ]);
        $this->assertSame('SPJ22', $app5->code);

        // Invalid codes
        $invalidCodes = [
            'S',
            'A',
            'ABCDEF',
            '123456',
            'SUMBER-PROT-1922',
            'SP-JO',
            'SP JO',
            'SP@1',
        ];

        foreach ($invalidCodes as $invalidCode) {
            try {
                $this->service->createApplication([
                    'code' => $invalidCode,
                    'name' => 'Invalid Code App',
                ]);
                $this->fail("Expected InvalidArgumentException for invalid application code [{$invalidCode}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Must be 2-5 uppercase alphanumeric characters', $e->getMessage());
            }
        }
    }

    /**
     * 4. Application code is strictly immutable on update.
     */
    public function test_application_code_is_strictly_immutable(): void
    {
        $app = $this->service->createApplication([
            'code' => 'LCK01',
            'name' => 'Original Name',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application code is strictly immutable and cannot be modified after creation.');

        $this->service->updateApplication($app, [
            'code' => 'MUTAT',
            'name' => 'Updated Name',
        ]);
    }

    /**
     * 5. Metadata update (name, description) succeeds and is audited.
     */
    public function test_updates_application_metadata_successfully(): void
    {
        $app = $this->service->createApplication([
            'code' => 'MET01',
            'name' => 'Initial Title',
            'description' => 'Initial Desc',
        ]);

        $updated = $this->service->updateApplication($app, [
            'name' => 'Revised Title',
            'description' => 'Revised Desc',
        ], $this->adminUser->id);

        $this->assertSame('Revised Title', $updated->name);
        $this->assertSame('Revised Desc', $updated->description);
        $this->assertSame('MET01', $updated->code);

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
            'code' => 'TOG01',
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
