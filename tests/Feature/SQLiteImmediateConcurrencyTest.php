<?php

namespace Tests\Feature;

use App\Database\SQLiteImmediateConnection;
use App\Enums\LicenseStatus;
use App\Models\Application;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SQLiteImmediateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = Application::create([
            'name' => 'Sumber Protein Jogja',
            'code' => 'SPJ',
            'is_active' => true,
        ]);
    }

    public function test_sqlite_connection_uses_custom_immediate_connection(): void
    {
        $connection = DB::connection('sqlite');

        $this->assertInstanceOf(SQLiteImmediateConnection::class, $connection);
    }

    public function test_sqlite_transaction_executes_begin_immediate_and_commits_atomically(): void
    {
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'TEST-KEY-1'),
            'key_masked' => 'SPJ-****-****-****-****-1111',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => now()->addYear(),
        ]);

        $result = DB::transaction(function () use ($license) {
            $fresh = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
            $fresh->status = LicenseStatus::SUSPENDED;
            $fresh->save();

            return $fresh->status;
        });

        $this->assertSame(LicenseStatus::SUSPENDED, $result);
        $this->assertSame(LicenseStatus::SUSPENDED, $license->fresh()->status);
    }

    public function test_sqlite_transaction_rolls_back_atomically_on_exception(): void
    {
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'TEST-KEY-2'),
            'key_masked' => 'SPJ-****-****-****-****-2222',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => now()->addYear(),
        ]);

        $thrown = false;
        try {
            DB::transaction(function () use ($license) {
                $fresh = License::where('id', $license->id)->lockForUpdate()->firstOrFail();
                $fresh->status = LicenseStatus::SUSPENDED;
                $fresh->save();

                throw new \RuntimeException('Simulated failure inside transaction');
            });
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(LicenseStatus::ACTIVE, $license->fresh()->status);
    }

    public function test_sqlite_nested_transaction_savepoints_function_properly(): void
    {
        $license = License::create([
            'application_id' => $this->application->id,
            'key_hash' => hash('sha256', 'TEST-KEY-3'),
            'key_masked' => 'SPJ-****-****-****-****-3333',
            'status' => LicenseStatus::ACTIVE,
            'expires_at' => now()->addYear(),
        ]);

        DB::transaction(function () use ($license) {
            $license->customer_name = 'Outer Name';
            $license->save();

            DB::transaction(function () use ($license) {
                $license->customer_email = 'inner@example.com';
                $license->save();
            });
        });

        $license->refresh();
        $this->assertSame('Outer Name', $license->customer_name);
        $this->assertSame('inner@example.com', $license->customer_email);
    }
}
