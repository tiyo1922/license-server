<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Web responses must include standard OWASP security headers.
     */
    public function test_web_responses_include_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    /**
     * API responses must also include standard OWASP security headers.
     */
    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    /**
     * HSTS header is present on HTTPS / X-Forwarded-Proto requests.
     */
    public function test_hsts_header_present_on_secure_requests(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
        ])->get('/login');

        $response->assertStatus(200);
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * Trusted proxies correctly resolve client IP from X-Forwarded-For.
     */
    public function test_trusted_proxies_resolve_client_ip(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.195',
        ])->getJson('/api/health');

        $response->assertStatus(200);
    }
}
