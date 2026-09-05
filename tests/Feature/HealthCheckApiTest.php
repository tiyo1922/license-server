<?php

namespace Tests\Feature;

use App\Services\License\Token\TokenSignerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HealthCheckApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Healthcheck returns 200 OK with expected structure when system is healthy.
     */
    public function test_healthcheck_returns_ok_when_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services' => [
                    'database',
                    'signer',
                ],
            ])
            ->assertJson([
                'status' => 'UP',
                'services' => [
                    'database' => 'OK',
                    'signer' => 'READY',
                ],
            ]);
    }

    /**
     * Healthcheck response contains zero secret, private key, or internal path leakage.
     */
    public function test_healthcheck_does_not_leak_secrets_or_env_vars(): void
    {
        $response = $this->getJson('/api/health');

        $content = $response->getContent();
        $this->assertStringNotContainsString('base64', strtolower($content));
        $this->assertStringNotContainsString('ed25519_private_key', strtolower($content));
        $this->assertStringNotContainsString('app_key', strtolower($content));
        $this->assertStringNotContainsString('database.sqlite', strtolower($content));
    }

    /**
     * Healthcheck returns 503 Service Unavailable when token signer is broken or unready.
     */
    public function test_healthcheck_returns_503_when_signer_fails(): void
    {
        $mockSigner = Mockery::mock(TokenSignerInterface::class);
        $mockSigner->shouldReceive('getKeyId')->andReturn('');

        $this->app->instance(TokenSignerInterface::class, $mockSigner);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'DOWN',
                'services' => [
                    'database' => 'OK',
                    'signer' => 'ERROR',
                ],
            ]);
    }
}
