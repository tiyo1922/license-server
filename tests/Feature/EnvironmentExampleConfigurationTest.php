<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvironmentExampleConfigurationTest extends TestCase
{
    /**
     * .env.example contains all required production configuration keys.
     */
    public function test_env_example_contains_all_required_keys(): void
    {
        $envExamplePath = base_path('.env.example');
        $this->assertFileExists($envExamplePath);

        $content = file_get_contents($envExamplePath);

        $requiredKeys = [
            'APP_NAME',
            'APP_ENV',
            'APP_KEY',
            'APP_DEBUG',
            'APP_URL',
            'TRUSTED_PROXIES',
            'ED25519_PRIVATE_KEY',
            'ED25519_PUBLIC_KEY',
            'ED25519_KEY_ID',
            'LICENSE_TOKEN_TTL',
            'LICENSE_ISSUER',
            'LICENSE_ROLLING_REFRESH_THRESHOLD',
            'LICENSE_OFFLINE_CLOCK_SKEW_SECONDS',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertStringContainsString("{$key}=", $content, "Missing required key [{$key}] in .env.example");
        }
    }
}
