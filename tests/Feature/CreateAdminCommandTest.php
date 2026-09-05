<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Admin user can be created via non-interactive CLI options.
     */
    public function test_creates_admin_via_options(): void
    {
        $this->artisan('license:create-admin', [
            '--name' => 'Lead Administrator',
            '--email' => 'admin@license.katresnanku.com',
            '--password' => 'VeryStrongPassword2026!',
        ])
            ->expectsOutputToContain('Administrator account successfully created!')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@license.katresnanku.com',
            'name' => 'Lead Administrator',
        ]);

        $user = User::where('email', 'admin@license.katresnanku.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('VeryStrongPassword2026!', $user->password));
        $this->assertNotEquals('VeryStrongPassword2026!', $user->password);
    }

    /**
     * Admin user can be created via interactive CLI prompts.
     */
    public function test_creates_admin_via_interactive_prompts(): void
    {
        $this->artisan('license:create-admin')
            ->expectsQuestion('Administrator Display Name (e.g. System Administrator)', 'Interactive Admin')
            ->expectsQuestion('Administrator Email Address', 'interactive@license.katresnanku.com')
            ->expectsQuestion('Administrator Password (min 10 characters)', 'SecurePassword987!')
            ->expectsQuestion('Confirm Administrator Password', 'SecurePassword987!')
            ->expectsOutputToContain('Administrator account successfully created!')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'interactive@license.katresnanku.com',
            'name' => 'Interactive Admin',
        ]);
    }

    /**
     * Rejects invalid email address.
     */
    public function test_rejects_invalid_email(): void
    {
        $this->artisan('license:create-admin', [
            '--name' => 'Admin Name',
            '--email' => 'not-a-valid-email',
            '--password' => 'ValidPassword123!',
        ])
            ->expectsOutputToContain('is not a valid email address')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', [
            'email' => 'not-a-valid-email',
        ]);
    }

    /**
     * Rejects short password (< 10 characters).
     */
    public function test_rejects_short_password(): void
    {
        $this->artisan('license:create-admin', [
            '--name' => 'Admin Name',
            '--email' => 'valid@license.katresnanku.com',
            '--password' => 'short',
        ])
            ->expectsOutputToContain('Password must be at least 10 characters long')
            ->assertExitCode(1);
    }

    /**
     * Rejects mismatched password confirmation in interactive mode.
     */
    public function test_rejects_mismatched_password_confirmation(): void
    {
        $this->artisan('license:create-admin')
            ->expectsQuestion('Administrator Display Name (e.g. System Administrator)', 'Admin User')
            ->expectsQuestion('Administrator Email Address', 'mismatch@license.katresnanku.com')
            ->expectsQuestion('Administrator Password (min 10 characters)', 'PasswordOne123!')
            ->expectsQuestion('Confirm Administrator Password', 'PasswordTwo456!')
            ->expectsOutputToContain('Password confirmation does not match')
            ->assertExitCode(1);
    }

    /**
     * Existing admin user is updated when force option is passed.
     */
    public function test_updates_existing_admin_with_force(): void
    {
        User::create([
            'name' => 'Original Admin',
            'email' => 'existing@license.katresnanku.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $this->artisan('license:create-admin', [
            '--name' => 'Updated Admin',
            '--email' => 'existing@license.katresnanku.com',
            '--password' => 'NewStrongPassword456!',
            '--force' => true,
        ])
            ->expectsOutputToContain('Administrator account successfully updated!')
            ->assertExitCode(0);

        $user = User::where('email', 'existing@license.katresnanku.com')->first();
        $this->assertEquals('Updated Admin', $user->name);
        $this->assertTrue(Hash::check('NewStrongPassword456!', $user->password));
    }
}
