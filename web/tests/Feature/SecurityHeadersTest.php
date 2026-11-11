<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Services\StudioLogos;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Security headers on every response (decision 42). */
class SecurityHeadersTest extends TestCase
{
    private function assertSecurityHeaders(TestResponse $response, bool $https = false): TestResponse
    {
        $response
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'")
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $https
            ? $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            : $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_pages_over_https_tell_browsers_to_stay_on_https(): void
    {
        $this->assertSecurityHeaders($this->get('https://localhost/')->assertOk(), https: true);
        $this->assertSecurityHeaders($this->get('https://localhost/pricing')->assertOk(), https: true);
    }

    public function test_over_plain_http_there_is_no_https_rule_but_everything_else_is_sent(): void
    {
        $this->assertSecurityHeaders($this->get('/')->assertOk());
    }

    public function test_studio_pages_admin_pages_the_api_errors_and_redirects_all_carry_them(): void
    {
        $studio = $this->studio();

        // Over HTTPS, as in production.
        $this->assertSecurityHeaders($this->get("https://localhost/{$studio->slug}")->assertOk(), https: true);
        $this->assertSecurityHeaders($this->get('https://localhost/studios')->assertRedirect('https://localhost/login'), https: true);
        $this->assertSecurityHeaders($this->get('https://localhost/no-such-studio/nothing-here')->assertNotFound(), https: true);
        $this->assertSecurityHeaders($this->post('https://localhost/login', [])->assertSessionHasErrors('email'), https: true);
        $this->assertSecurityHeaders($this->get('https://localhost/up')->assertOk(), https: true);
        $this->assertSecurityHeaders($this->getJson("https://localhost/api/v1/studios/{$studio->slug}")->assertOk(), https: true);
        $this->assertSecurityHeaders($this->getJson('https://localhost/api/v1/nope')->assertNotFound(), https: true);

        $this->actingAs($this->userIn($studio, 'owner'));
        $this->assertSecurityHeaders($this->get("https://localhost/{$studio->slug}/admin")->assertOk(), https: true);
    }

    public function test_the_maintenance_page_carries_them_too(): void
    {
        $this->app->maintenanceMode()->activate([]);
        try {
            $this->assertSecurityHeaders($this->get('/')->assertStatus(503));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_a_response_with_its_own_policy_keeps_it(): void
    {
        $studio = $this->studio();
        $im = imagecreatetruecolor(200, 100);
        ob_start();
        imagepng($im);
        app(StudioLogos::class)->store($studio, (string) ob_get_clean());

        $this->get($studio->refresh()->logoUrl())->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', SecurityHeaders::HEADERS['Referrer-Policy']);
    }
}
