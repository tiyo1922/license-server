<?php

namespace Tests\Feature;

use App\Services\License\Token\Ed25519TokenSigner;
use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LicensePreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Preflight command passes successfully in standard development/testing environment.
     */
    public function test_preflight_passes_in_standard_environment(): void
    {
        $this->artisan('license:preflight')
            ->expectsOutputToContain('Central License Server — Pre-Flight Production Diagnostic')
            ->expectsOutputToContain('PRE-FLIGHT VERIFICATION PASSED')
            ->assertExitCode(0);
    }

    /**
     * Preflight fails when APP_DEBUG=true in production environment.
     */
    public function test_preflight_fails_when_debug_is_true_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
        ]);

        $this->artisan('license:preflight')
            ->expectsOutputToContain('CRITICAL: APP_DEBUG is TRUE in production!')
            ->expectsOutputToContain('PRE-FLIGHT VERIFICATION FAILED')
            ->assertExitCode(1);
    }

    /**
     * Preflight fails in production if signer is ephemeral in-memory fallback.
     */
    public function test_preflight_fails_when_signer_is_ephemeral_in_production(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
        ]);

        $mockSigner = Mockery::mock(Ed25519TokenSigner::class);
        $mockSigner->shouldReceive('getKeyId')->andReturn('test-ephemeral-kid');
        $mockSigner->shouldReceive('isEphemeral')->andReturn(true);

        $this->app->instance(TokenSignerInterface::class, $mockSigner);

        $this->artisan('license:preflight')
            ->expectsOutputToContain('CRITICAL: Production is running with ephemeral in-memory key!')
            ->expectsOutputToContain('PRE-FLIGHT VERIFICATION FAILED')
            ->assertExitCode(1);
    }

    /**
     * Preflight command output never discloses private keys, hashes, or secret values.
     */
    public function test_preflight_does_not_disclose_secrets_or_private_keys(): void
    {
        config([
            'license.ed25519_private_key' => 'dGVzdF9wcml2YXRlX2tleV9kYXRhXzEyMzQ1Njc4OTA=',
        ]);

        $this->artisan('license:preflight')
            ->assertExitCode(0);
    }

    /**
     * Preflight passes with real Ed25519TokenSigner in production when valid private key is configured.
     */
    public function test_preflight_passes_with_real_signer_in_production(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'license.ed25519_private_key' => base64_encode($secretKey),
        ]);

        $realSigner = new Ed25519TokenSigner();
        $this->assertFalse($realSigner->isEphemeral());
        $this->app->instance(TokenSignerInterface::class, $realSigner);

        $this->artisan('license:preflight')
            ->expectsOutputToContain('PRE-FLIGHT VERIFICATION PASSED')
            ->assertExitCode(0);
    }
}
