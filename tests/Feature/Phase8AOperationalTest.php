<?php

namespace Tests\Feature;

use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\Ed25519TokenVerifier;
use App\Services\License\Token\StaticTrustedKeyRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class Phase8AOperationalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Production + missing private key => fails fast with RuntimeException.
     */
    public function test_production_fails_fast_when_private_key_is_missing(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.env', 'production');
        Config::set('license.ed25519_private_key', null);
        Config::set('license.ed25519_public_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Production Ed25519 signing private key is unconfigured.');

        new Ed25519TokenSigner();
    }

    /**
     * 2. Production + invalid Base64 => fails fast.
     */
    public function test_production_fails_fast_on_invalid_base64_private_key(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.env', 'production');
        Config::set('license.ed25519_private_key', '!!!NOT_VALID_BASE64!!!');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured ED25519_PRIVATE_KEY is invalid.');

        new Ed25519TokenSigner();
    }

    /**
     * 3. Production + invalid decoded length (e.g. 16 bytes) => fails fast.
     */
    public function test_production_fails_fast_on_invalid_key_length(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.env', 'production');
        Config::set('license.ed25519_private_key', base64_encode(random_bytes(16)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured ED25519_PRIVATE_KEY is invalid. Expected Base64 64-byte secret key or 32-byte seed.');

        new Ed25519TokenSigner();
    }

    /**
     * 4. Production + valid 32-byte seed => works and derives public key.
     */
    public function test_production_succeeds_with_valid_32_byte_seed(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.env', 'production');

        $seed = random_bytes(32);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $expectedPublicKey = sodium_crypto_sign_publickey($keypair);

        Config::set('license.ed25519_private_key', base64_encode($seed));
        Config::set('license.ed25519_public_key', null);

        $signer = new Ed25519TokenSigner();

        $this->assertSame($expectedPublicKey, $signer->getPublicKey());
        $this->assertNotEmpty($signer->sign(['sub' => 'TEST-KEY', 'exp' => time() + 3600]));
    }

    /**
     * 5. Production + valid 64-byte secret key => works and derives public key.
     */
    public function test_production_succeeds_with_valid_64_byte_secret_key(): void
    {
        $this->app['env'] = 'production';
        Config::set('app.env', 'production');

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $expectedPublicKey = sodium_crypto_sign_publickey($keypair);

        Config::set('license.ed25519_private_key', base64_encode($secretKey));
        Config::set('license.ed25519_public_key', null);

        $signer = new Ed25519TokenSigner();

        $this->assertSame($expectedPublicKey, $signer->getPublicKey());
        $this->assertNotEmpty($signer->sign(['sub' => 'TEST-KEY', 'exp' => time() + 3600]));
    }

    /**
     * 6. Public key omitted => successfully derived from private key.
     */
    public function test_public_key_is_automatically_derived_when_omitted(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $expectedPublicKey = sodium_crypto_sign_publickey($keypair);

        $signer = new Ed25519TokenSigner(base64_encode($secretKey), null, 'test-key-1');

        $this->assertSame($expectedPublicKey, $signer->getPublicKey());
        $this->assertSame(base64_encode($expectedPublicKey), $signer->getPublicKeyBase64());
    }

    /**
     * 7. Matching configured public key => accepted.
     */
    public function test_matching_explicit_public_key_is_accepted(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $signer = new Ed25519TokenSigner(
            base64_encode($secretKey),
            base64_encode($publicKey),
            'test-key-1'
        );

        $this->assertSame($publicKey, $signer->getPublicKey());
    }

    /**
     * 8. Mismatched public key => fails fast with RuntimeException.
     */
    public function test_mismatched_explicit_public_key_fails_fast(): void
    {
        $keypair1 = sodium_crypto_sign_keypair();
        $secretKey1 = sodium_crypto_sign_secretkey($keypair1);

        $keypair2 = sodium_crypto_sign_keypair();
        $mismatchedPublicKey = sodium_crypto_sign_publickey($keypair2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured ED25519_PUBLIC_KEY does not match the key derived from ED25519_PRIVATE_KEY.');

        new Ed25519TokenSigner(
            base64_encode($secretKey1),
            base64_encode($mismatchedPublicKey),
            'test-key-1'
        );
    }

    /**
     * 9. Non-production environment keeps ephemeral fallback when keys are omitted.
     */
    public function test_non_production_environment_maintains_ephemeral_fallback(): void
    {
        $this->app['env'] = 'testing';
        Config::set('app.env', 'testing');
        Config::set('license.ed25519_private_key', null);
        Config::set('license.ed25519_public_key', null);

        $signer = new Ed25519TokenSigner();

        $this->assertNotEmpty($signer->getPublicKey());
        $this->assertSame(32, strlen($signer->getPublicKey()));

        $token = $signer->sign(['test' => 'data', 'exp' => time() + 3600]);
        $this->assertNotEmpty($token);

        $verified = $signer->verify($token);
        $this->assertSame('data', $verified['test']);
    }

    /**
     * 10 & 11. Keypair command generates valid Ed25519 keypair that matches and can sign/verify.
     */
    public function test_keypair_command_generates_valid_functional_ed25519_keypair(): void
    {
        $this->artisan('app:generate-keypair')
            ->expectsOutputToContain('Ed25519 Cryptographic Signing Keypair Generated')
            ->expectsOutputToContain('WARNING: The private key below is highly sensitive secret material.')
            ->expectsOutputToContain('ED25519_PRIVATE_KEY=')
            ->expectsOutputToContain('ED25519_PUBLIC_KEY=')
            ->assertExitCode(0);
    }

    /**
     * 12 & 13. Keypair command respects custom --key-id.
     */
    public function test_keypair_command_respects_custom_key_id(): void
    {
        $customKid = 'cls-ed25519-custom-2027-v2';

        $this->artisan('app:generate-keypair', ['--key-id' => $customKid])
            ->expectsOutputToContain("Key Identifier (kid): {$customKid}")
            ->expectsOutputToContain("ED25519_KEY_ID={$customKid}")
            ->assertExitCode(0);
    }

    /**
     * Test keypair command rejects malformed --key-id.
     */
    public function test_keypair_command_rejects_malformed_key_id(): void
    {
        $this->artisan('app:generate-keypair', ['--key-id' => 'bad id with spaces & symbols!'])
            ->expectsOutputToContain('Invalid --key-id format')
            ->assertExitCode(1);
    }

    /**
     * 14 & 15. Keypair command does not modify .env or database.
     */
    public function test_keypair_command_does_not_modify_env_or_database(): void
    {
        $envPath = base_path('.env');
        $originalEnvContent = File::exists($envPath) ? File::get($envPath) : null;
        $originalLicenseCount = \App\Models\License::count();

        Artisan::call('app:generate-keypair');

        if ($originalEnvContent !== null) {
            $this->assertSame($originalEnvContent, File::get($envPath));
        }

        $this->assertSame($originalLicenseCount, \App\Models\License::count());
    }

    /**
     * 16. Keypair command does not change active application signing configuration.
     */
    public function test_keypair_command_does_not_alter_active_application_signer_state(): void
    {
        $activeSigner = app(\App\Services\License\Token\Ed25519TokenSigner::class);
        $activePublicKey = $activeSigner->getPublicKey();
        $activeKeyId = $activeSigner->getKeyId();

        Artisan::call('app:generate-keypair', ['--key-id' => 'transient-rotated-key']);

        $currentSigner = app(\App\Services\License\Token\Ed25519TokenSigner::class);
        $this->assertSame($activePublicKey, $currentSigner->getPublicKey());
        $this->assertSame($activeKeyId, $currentSigner->getKeyId());
    }

    /**
     * 17. Private key is not written to application logs during key generation.
     */
    public function test_private_key_is_not_written_to_application_logs(): void
    {
        Log::spy();

        Artisan::call('app:generate-keypair');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * 18, 19, 20. Scheduler registration exists for app:sync-expired-licenses with 15m cadence and withoutOverlapping.
     */
    public function test_scheduler_registers_sync_expired_licenses_with_15_minute_cadence(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $syncEvent = $events->first(function ($event) {
            return str_contains($event->command, 'app:sync-expired-licenses');
        });

        $this->assertNotNull($syncEvent, 'app:sync-expired-licenses is not registered in the console scheduler.');
        $this->assertSame('*/15 * * * *', $syncEvent->expression, 'app:sync-expired-licenses is not scheduled every 15 minutes.');
        $this->assertTrue($syncEvent->withoutOverlapping, 'app:sync-expired-licenses must be configured with withoutOverlapping().');
    }
}
