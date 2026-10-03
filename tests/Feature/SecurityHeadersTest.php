<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    public function test_http_response_carries_csp_and_no_hsts(): void
    {
        $response = $this->get('http://localhost/up');

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', self::CSP);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_response_adds_hsts(): void
    {
        $response = $this->get('https://localhost/up');

        $response->assertOk();
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $response->assertHeader('Content-Security-Policy', self::CSP);
    }
}
