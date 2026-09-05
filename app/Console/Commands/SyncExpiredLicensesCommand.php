<?php

namespace App\Console\Commands;

use App\Services\License\LicenseLifecycleService;
use Illuminate\Console\Command;

class SyncExpiredLicensesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'licenses:sync-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize persistent status for time-bound licenses whose expiration timestamp has passed';

    /**
     * Execute the console command.
     */
    public function handle(LicenseLifecycleService $lifecycleService): int
    {
        $this->info('Starting license expiration synchronization...');

        $count = $lifecycleService->syncExpiredLicenses();

        $this->info("Successfully synchronized {$count} expired license(s).");

        return self::SUCCESS;
    }
}
