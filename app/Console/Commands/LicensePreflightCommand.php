<?php

namespace App\Console\Commands;

use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class LicensePreflightCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:preflight';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform production pre-flight diagnostic checks on runtime, cryptography, database, and security configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('================================================================');
        $this->info('  Central License Server — Pre-Flight Production Diagnostic');
        $this->info('  Authority: ' . (config('app.url') ?: 'https://license.katresnanku.com'));
        $this->info('================================================================');
        $this->info('');

        $results = [];
        $hasFailure = false;

        // 1. PHP Runtime & Extensions
        $phpVersion = PHP_VERSION;
        $phpPassed = PHP_VERSION_ID >= 80300;
        $results[] = [
            'Category' => 'Runtime',
            'Check' => 'PHP Version (>= 8.3.0)',
            'Status' => $phpPassed ? '<info>PASS</info>' : '<error>FAIL</error>',
            'Details' => "Current: PHP {$phpVersion}",
        ];
        if (! $phpPassed) {
            $hasFailure = true;
        }

        $sodiumLoaded = extension_loaded('sodium') && function_exists('sodium_crypto_sign_detached');
        $results[] = [
            'Category' => 'Runtime',
            'Check' => 'Sodium Extension (Ed25519)',
            'Status' => $sodiumLoaded ? '<info>PASS</info>' : '<error>FAIL</error>',
            'Details' => $sodiumLoaded ? 'ext-sodium active & Edwards Curve available' : 'Missing ext-sodium extension',
        ];
        if (! $sodiumLoaded) {
            $hasFailure = true;
        }

        // 2. Storage & Permissions
        $storageWritable = is_writable(storage_path());
        $bootstrapCacheWritable = is_writable(base_path('bootstrap/cache'));
        $results[] = [
            'Category' => 'Filesystem',
            'Check' => 'Storage Directory Writable',
            'Status' => $storageWritable ? '<info>PASS</info>' : '<error>FAIL</error>',
            'Details' => storage_path(),
        ];
        if (! $storageWritable) {
            $hasFailure = true;
        }

        $results[] = [
            'Category' => 'Filesystem',
            'Check' => 'Bootstrap Cache Writable',
            'Status' => $bootstrapCacheWritable ? '<info>PASS</info>' : '<error>FAIL</error>',
            'Details' => base_path('bootstrap/cache'),
        ];
        if (! $bootstrapCacheWritable) {
            $hasFailure = true;
        }

        // 3. Database Connectivity & SQLite Readiness
        $dbConnected = false;
        $dbDetails = '';
        try {
            DB::connection()->getPdo();
            $dbConnected = true;
            $driver = DB::connection()->getDriverName();
            $dbDetails = "Connected ({$driver})";
        } catch (Throwable $e) {
            $dbDetails = 'Connection error: ' . $e->getMessage();
        }

        $results[] = [
            'Category' => 'Database',
            'Check' => 'Database Connectivity',
            'Status' => $dbConnected ? '<info>PASS</info>' : '<error>FAIL</error>',
            'Details' => $dbDetails,
        ];
        if (! $dbConnected) {
            $hasFailure = true;
        }

        // SQLite Specific Checks
        if (config('database.default') === 'sqlite') {
            $sqlitePath = config('database.connections.sqlite.database');
            if ($sqlitePath !== ':memory:') {
                $sqliteExists = file_exists($sqlitePath);
                $sqliteWritable = $sqliteExists && is_writable($sqlitePath);
                $results[] = [
                    'Category' => 'Database',
                    'Check' => 'SQLite Database File Writable',
                    'Status' => ($sqliteExists && $sqliteWritable) ? '<info>PASS</info>' : '<error>FAIL</error>',
                    'Details' => $sqliteExists ? ($sqliteWritable ? 'File exists and writable' : 'File exists but NOT writable') : 'File not found',
                ];
                if (! $sqliteExists || ! $sqliteWritable) {
                    $hasFailure = true;
                }
            }
        }

        // 4. Migration Status
        $migrationStatus = 'PASS';
        $migrationDetails = 'All migrations executed';
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles($migrator->paths());
            $ran = $migrator->getRepository()->getRan();
            $pending = count($files) - count($ran);

            if ($pending > 0) {
                $migrationStatus = '<error>FAIL</error>';
                $migrationDetails = "{$pending} pending migrations detected. Run: php artisan migrate";
                $hasFailure = true;
            } else {
                $migrationStatus = '<info>PASS</info>';
                $migrationDetails = count($ran) . ' migrations up-to-date';
            }
        } catch (Throwable) {
            $migrationStatus = '<comment>WARN</comment>';
            $migrationDetails = 'Unable to verify migration table (database may be uninitialized)';
        }

        $results[] = [
            'Category' => 'Database',
            'Check' => 'Migration Status',
            'Status' => $migrationStatus,
            'Details' => $migrationDetails,
        ];

        // 5. Environment & Security Configuration
        $appEnv = config('app.env');
        $appDebug = config('app.debug');

        if ($appEnv === 'production') {
            $debugPassed = $appDebug === false;
            $results[] = [
                'Category' => 'Security',
                'Check' => 'Production Debug Mode (APP_DEBUG=false)',
                'Status' => $debugPassed ? '<info>PASS</info>' : '<error>FAIL</error>',
                'Details' => $debugPassed ? 'Debug mode disabled' : 'CRITICAL: APP_DEBUG is TRUE in production!',
            ];
            if (! $debugPassed) {
                $hasFailure = true;
            }
        } else {
            $results[] = [
                'Category' => 'Security',
                'Check' => 'Application Environment',
                'Status' => '<comment>INFO</comment>',
                'Details' => "Current environment: {$appEnv} (Debug: " . ($appDebug ? 'true' : 'false') . ')',
            ];
        }

        // 6. Ed25519 Cryptographic Signer Configuration
        $signerStatus = 'PASS';
        $signerDetails = '';
        try {
            $signer = app(TokenSignerInterface::class);
            $keyId = $signer->getKeyId();

            if (empty($keyId)) {
                $signerStatus = '<error>FAIL</error>';
                $signerDetails = 'Missing Key ID (kid)';
                $hasFailure = true;
            } elseif ($appEnv === 'production' && $signer instanceof Ed25519TokenSigner && $signer->isEphemeral()) {
                $signerStatus = '<error>FAIL</error>';
                $signerDetails = 'CRITICAL: Production is running with ephemeral in-memory key! Configure ED25519_PRIVATE_KEY.';
                $hasFailure = true;
            } else {
                $signerStatus = '<info>PASS</info>';
                $signerDetails = "Key ID [{$keyId}] configured and functional";
            }
        } catch (Throwable $e) {
            $signerStatus = '<error>FAIL</error>';
            $signerDetails = 'Signer initialization failure: ' . $e->getMessage();
            $hasFailure = true;
        }

        $results[] = [
            'Category' => 'Cryptography',
            'Check' => 'Ed25519 Signer Readiness',
            'Status' => $signerStatus,
            'Details' => $signerDetails,
        ];

        // 7. Scheduler Registration Check
        $schedulerRegistered = false;
        try {
            $schedule = app(Schedule::class);
            foreach ($schedule->events() as $event) {
                if (str_contains($event->command ?? '', 'app:sync-expired-licenses')) {
                    $schedulerRegistered = true;
                    break;
                }
            }
        } catch (Throwable) {
            // Ignore schedule inspection error
        }

        $results[] = [
            'Category' => 'Scheduler',
            'Check' => 'Expiration Sweep Task Registered',
            'Status' => $schedulerRegistered ? '<info>PASS</info>' : '<comment>WARN</comment>',
            'Details' => $schedulerRegistered ? 'app:sync-expired-licenses registered (15-min cadence)' : 'Ensure schedule:run is active in crontab',
        ];

        // Render Table
        $this->table(['Category', 'Check', 'Status', 'Details'], $results);

        $this->info('');
        if ($hasFailure) {
            $this->error('❌ PRE-FLIGHT VERIFICATION FAILED: Critical configuration errors must be resolved before production launch.');
            return self::FAILURE;
        }

        $this->info('✅ PRE-FLIGHT VERIFICATION PASSED: License Server is ready for production operation.');
        return self::SUCCESS;
    }
}
