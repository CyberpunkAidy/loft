<?php

namespace Tests\Feature;

use Tests\TestCase;

/** TRUSTED_PROXIES (bootstrap/app.php): off by default, on behind a load balancer such as Render's. */
class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setTrustedProxies(null);
        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->get('/login', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('action="http://', false)
            ->assertDontSee('action="https://', false);
    }

    public function test_forwarded_headers_are_honored_when_proxies_are_trusted(): void
    {
        $this->setTrustedProxies('*');
        $this->refreshApplication(); // the middleware reads TRUSTED_PROXIES while the app boots

        $this->get('/login', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('action="https://', false);
    }

    private function setTrustedProxies(?string $value): void
    {
        if ($value === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

            return;
        }
        putenv("TRUSTED_PROXIES={$value}");
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
    }
}
