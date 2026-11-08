<?php

namespace Tests\Feature;

use App\Support\Color;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    public function test_text_on_the_brand_color_stays_readable(): void
    {
        // Dark brand colors keep white labels and are already readable as text.
        $this->assertSame('#ffffff', Color::inkOn('#0f766e'));
        $this->assertSame('#0f766e', Color::textOnWhite('#0f766e'));

        // Light ones get dark labels, and are darkened just enough to read as text on white.
        $this->assertSame(Color::INK, Color::inkOn('#facc15'));
        $text = Color::textOnWhite('#facc15');
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($text, '#ffffff'));
        $this->assertLessThan(5.5, Color::contrast($text, '#ffffff'));
    }

    public function test_studio_pages_use_the_studios_colors(): void
    {
        $studio = $this->studio(['name' => 'élan Dance', 'accent_color' => '#facc15']);

        $this->get("/{$studio->slug}")->assertOk()
            ->assertSee('--accent: #facc15; --accent-ink: #17141f; --accent-text: '.Color::textOnWhite('#facc15').';', false)
            ->assertSee('<span class="brand-mark" aria-hidden="true">É</span>', false)
            ->assertSee('data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg"'), false)
            ->assertSee('aria-label="'.config('app.name').'"', false); // "Powered by" logo
    }

    public function test_platform_pages_show_the_logo(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<body class="platform">', false)
            ->assertSee('class="logo"', false)
            ->assertSee('href="'.asset('favicon.svg').'"', false)
            ->assertDontSee('--accent-ink', false);
    }
}
