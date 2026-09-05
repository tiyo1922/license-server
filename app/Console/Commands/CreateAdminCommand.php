<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:create-admin
                            {--email= : The email address for the administrator}
                            {--name= : The display name for the administrator}
                            {--password= : The plaintext password (optional, prompted securely if omitted)}
                            {--force : Force overwrite if an administrator with the given email already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Securely provision or update an administrator account for the Central License Server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('================================================================');
        $this->info('  Central License Server — Secure Admin User Provisioning');
        $this->info('================================================================');
        $this->info('');

        // 1. Resolve Name
        $name = $this->option('name');
        if (empty($name)) {
            $name = $this->ask('Administrator Display Name (e.g. System Administrator)');
        }
        $name = trim((string) $name);
        if (strlen($name) < 2 || strlen($name) > 100) {
            $this->error('Error: Administrator name must be between 2 and 100 characters.');
            return self::FAILURE;
        }

        // 2. Resolve & Validate Email
        $email = $this->option('email');
        if (empty($email)) {
            $email = $this->ask('Administrator Email Address');
        }
        $email = strtolower(trim((string) $email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Error: [{$email}] is not a valid email address.");
            return self::FAILURE;
        }

        // 3. Resolve & Validate Password
        $password = $this->option('password');
        if (empty($password)) {
            $password = $this->secret('Administrator Password (min 10 characters)');
            $confirmation = $this->secret('Confirm Administrator Password');

            if ($password !== $confirmation) {
                $this->error('Error: Password confirmation does not match.');
                return self::FAILURE;
            }
        }

        if (strlen((string) $password) < 10) {
            $this->error('Error: Password must be at least 10 characters long.');
            return self::FAILURE;
        }

        // 4. Check for Existing Account
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            if (! $this->option('force')) {
                if (! $this->confirm("An account with email [{$email}] already exists. Reset password and update display name?", false)) {
                    $this->warn('Operation cancelled. Existing account was NOT modified.');
                    return self::SUCCESS;
                }
            }
        }

        // 5. Create or Update Account Atomically
        $action = $existingUser ? 'updated' : 'created';
        DB::transaction(function () use ($existingUser, $email, $name, $password) {
            if ($existingUser) {
                $existingUser->name = $name;
                $existingUser->password = Hash::make($password);
                $existingUser->save();
            } else {
                User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
            }
        });

        $this->info('');
        $this->info("✅ Administrator account successfully {$action}!");
        $this->table(['Field', 'Value'], [
            ['Name', $name],
            ['Email', $email],
            ['Action', strtoupper($action)],
            ['Status', 'ACTIVE / VERIFIED'],
        ]);
        $this->info('');
        $this->info('You may now log in to the admin console at: ' . url('/login'));

        return self::SUCCESS;
    }
}
