<?php

namespace Tests\Feature;

use App\Exceptions\LicenseGenerationException;
use App\Models\Application;
use App\Models\License;
use App\Models\LicenseLog;
use App\Models\User;
use App\Services\License\GeneratedLicenseKey;
use App\Services\License\LicenseCreationService;
use App\Services\License\LicenseKeyGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LicenseCollisionAndTransactionTest extends TestCase
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

    public function test_retries_with_fresh_key_on_key_hash_unique_collision(): void
    {
        // 1. Create an existing license to occupy key_hash
        $collidingKey = new GeneratedLicenseKey(
            plaintextKey: 'SPJ-1111-2222-3333-4444-5555',
            canonicalKey: 'SPJ-1111-2222-3333-4444-5555',
            keyHash: hash('sha256', 'SPJ-1111-2222-3333-4444-5555'),
            keyMasked: 'SPJ-****-****-****-****-5555'
        );

        License::create([
            'application_id' => $this->application->id,
            'key_hash' => $collidingKey->keyHash,
            'key_masked' => $collidingKey->keyMasked,
            'status' => 'UNUSED',
        ]);

        $freshKey = new GeneratedLicenseKey(
            plaintextKey: 'SPJ-9999-8888-7777-6666-0000',
            canonicalKey: 'SPJ-9999-8888-7777-6666-0000',
            keyHash: hash('sha256', 'SPJ-9999-8888-7777-6666-0000'),
            keyMasked: 'SPJ-****-****-****-****-0000'
        );

        // 2. Mock LicenseKeyGenerator: attempt 1 returns colliding key, attempt 2 returns fresh key
        $generatorMock = Mockery::mock(LicenseKeyGenerator::class);
        $generatorMock->shouldReceive('generate')
            ->with('SPJ')
            ->andReturn($collidingKey, $freshKey);

        $service = new LicenseCreationService($generatorMock);

        $result = $service->createLicense($this->application, ['customer_name' => 'Collision Resolved'], $this->admin->id);

        $this->assertSame($freshKey->plaintextKey, $result['plaintextKey']);
        $this->assertSame($freshKey->keyHash, $result['license']->key_hash);
        $this->assertDatabaseCount('licenses', 2);
    }

    public function test_aborts_and_throws_exception_after_3_consecutive_collisions(): void
    {
        $collidingKey = new GeneratedLicenseKey(
            plaintextKey: 'SPJ-COLL-ISIO-NKEY-1111-9999',
            canonicalKey: 'SPJ-COLL-ISIO-NKEY-1111-9999',
            keyHash: hash('sha256', 'SPJ-COLL-ISIO-NKEY-1111-9999'),
            keyMasked: 'SPJ-****-****-****-****-9999'
        );

        License::create([
            'application_id' => $this->application->id,
            'key_hash' => $collidingKey->keyHash,
            'key_masked' => $collidingKey->keyMasked,
            'status' => 'UNUSED',
        ]);

        $generatorMock = Mockery::mock(LicenseKeyGenerator::class);
        // Returns colliding key on all attempts
        $generatorMock->shouldReceive('generate')
            ->with('SPJ')
            ->times(3)
            ->andReturn($collidingKey);

        $service = new LicenseCreationService($generatorMock);

        $this->expectException(LicenseGenerationException::class);
        $this->expectExceptionMessage('Unable to generate a unique license key after 3 attempts.');

        $service->createLicense($this->application, [], $this->admin->id);
    }

    public function test_unrelated_database_exceptions_are_not_treated_as_collision(): void
    {
        $generatorMock = Mockery::mock(LicenseKeyGenerator::class);
        $generatorMock->shouldReceive('generate')
            ->once()
            ->andReturn(new GeneratedLicenseKey(
                plaintextKey: 'SPJ-AAAA-BBBB-CCCC-DDDD-EEEE',
                canonicalKey: 'SPJ-AAAA-BBBB-CCCC-DDDD-EEEE',
                keyHash: hash('sha256', 'SPJ-AAAA-BBBB-CCCC-DDDD-EEEE'),
                keyMasked: 'SPJ-****-****-****-****-EEEE'
            ));

        $service = new LicenseCreationService($generatorMock);

        // Invalid foreign key or bad application causing foreign key violation
        $nonExistentApp = new Application();
        $nonExistentApp->id = 999999;
        $nonExistentApp->code = 'SPJ';
        $nonExistentApp->is_active = true;

        try {
            $service->createLicense($nonExistentApp, [], $this->admin->id);
            $this->fail('Expected QueryException to be thrown without retry.');
        } catch (QueryException $e) {
            $this->assertFalse($service->isKeyHashUniqueViolation($e));
        }
    }

    public function test_transaction_rolls_back_if_audit_logging_fails(): void
    {
        $generatorMock = Mockery::mock(LicenseKeyGenerator::class);
        $generatorMock->shouldReceive('generate')
            ->once()
            ->andReturn(new GeneratedLicenseKey(
                plaintextKey: 'SPJ-1111-2222-3333-4444-5555',
                canonicalKey: 'SPJ-1111-2222-3333-4444-5555',
                keyHash: hash('sha256', 'SPJ-1111-2222-3333-4444-5555'),
                keyMasked: 'SPJ-****-****-****-****-5555'
            ));

        $service = new LicenseCreationService($generatorMock);

        // Hook into LicenseLog saving to throw exception
        LicenseLog::saving(function () {
            throw new \RuntimeException('Simulated audit log insertion failure.');
        });

        try {
            $service->createLicense($this->application, [], $this->admin->id);
            $this->fail('Expected RuntimeException during audit log insertion.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated audit log insertion failure.', $e->getMessage());
        }

        // Verify that because audit log failed, the entire transaction rolled back and no license was saved
        $this->assertDatabaseCount('licenses', 0);
        $this->assertDatabaseCount('license_logs', 0);
    }
}

