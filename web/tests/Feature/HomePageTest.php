<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The marketing home page (decision 39): what it promises, the numbers it shows, and what search and social sites read. */
class HomePageTest extends TestCase
{
    public function test_the_home_page_sells_the_flat_price(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Your whole studio,')
            ->assertSee('one flat price.')
            ->assertSee('For aerial, pole, Pilates, yoga, dance and fitness studios')
            ->assertSee('for $49 a month')
            ->assertSee('Free for 30 days')
            ->assertSee('<strong>0%</strong><span>of your sales to Loft</span>', false)
            ->assertSee('Booking that fills every spot')
            ->assertSee('We’ll move your members over for free.', false)
            ->assertSee('mailto:hello@loftstudio.app', false)
            ->assertSee('No. Members pay you through your own Stripe account')
            ->assertSee('online booking pauses until you subscribe', false)
            ->assertSee(route('signup'), false)
            ->assertSee(route('pricing'), false);
    }

    public function test_the_calculator_starts_from_a_typical_studio(): void
    {
        // $199 a month of software adding 1% to $10,000 a month of card sales, against $49 a month of Loft.
        $this->get('/')->assertOk()
            ->assertSee('data-monthly="49" data-fee="0" data-currency="USD"', false)
            ->assertSee('name="software" min="0" step="1" inputmode="decimal" value="199"', false)
            ->assertSeeInOrder(['data-out="now">$3,588', 'data-out="loft">$588', 'You’d keep', 'data-out="saved">$3,000'], false);
    }

    public function test_the_page_follows_the_configured_price_fee_and_support_address(): void
    {
        config([
            'loft.billing.monthly_cents' => 5900, 'loft.billing.trial_days' => 14, 'loft.billing.grace_days' => 3,
            'loft.stripe.platform_fee_percent' => 1.5, 'loft.support_email' => 'help@example.com',
        ]);

        // Loft: 12 × ($59 + 1.5% of $10,000) = $2,508; now $3,588, so $1,080 kept.
        $this->get('/')->assertOk()
            ->assertSee('for $59 a month')
            ->assertSee('Free for 14 days')
            ->assertSee('<strong>1.5%</strong><span>Loft fee on online sales</span>', false)
            ->assertDontSee('of your sales to Loft')
            ->assertSee('Loft adds 1.5% to online sales.')
            ->assertSee('everything keeps working for 3 more days', false)
            ->assertSee('data-monthly="59" data-fee="1.5"', false)
            ->assertSeeInOrder(['data-out="now">$3,588', 'data-out="loft">$2,508', 'data-out="saved">$1,080'], false)
            ->assertSee('mailto:help@example.com', false)
            ->assertDontSee('hello@loftstudio.app');
    }

    public function test_search_and_social_sites_get_a_description_a_share_image_and_structured_data(): void
    {
        $html = $this->get('/')->assertOk()
            ->assertSee('<meta name="description" content="Booking, memberships, waivers, check-in, point of sale and video for class-based studios. $49 a month', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'">', false)
            ->assertSee('<meta property="og:image" content="'.asset('images/og/loft-home.jpg').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->getContent();

        $this->assertFileExists(public_path('images/og/loft-home.jpg'));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('images/og/loft-home.jpg')), 0, 2));

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
        [$app, $faq] = $data['@graph'];
        $this->assertSame('SoftwareApplication', $app['@type']);
        $this->assertSame(['@type' => 'Offer', 'price' => '49.00', 'priceCurrency' => 'USD', 'description' => 'Per studio, per month. Free for 30 days.'], $app['offers']);
        $this->assertSame('FAQPage', $faq['@type']);
        $this->assertCount(7, $faq['mainEntity']);
        $this->assertSame('Do you take a cut of my sales?', $faq['mainEntity'][0]['name']);
        // Each answer is on the page too, as a question someone can open.
        foreach ($faq['mainEntity'] as $q) {
            $this->assertStringContainsString('<summary>'.e($q['name']).'</summary>', $html);
        }

        // The pricing page has its own description; studio pages have none from the platform.
        $this->get('/pricing')->assertOk()->assertSee('<meta name="description" content="Loft costs $49 a month per studio', false);
    }

    public function test_every_screenshot_exists_at_the_size_the_page_says_and_has_alt_text(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('#<img\b[^>]*>#', $html, $tags);
        $this->assertCount(8, $tags[0]);

        foreach ($tags[0] as $i => $tag) {
            preg_match('#src="([^"]+)"#', $tag, $src);
            preg_match('#width="(\d+)" height="(\d+)"#', $tag, $size);
            preg_match('#alt="([^"]*)"#', $tag, $alt);
            $file = public_path(ltrim(parse_url($src[1], PHP_URL_PATH), '/'));

            $this->assertFileExists($file);
            $this->assertSame([(int) $size[1], (int) $size[2]], array_slice(getimagesize($file), 0, 2), basename($file));
            $this->assertGreaterThan(30, strlen($alt[1] ?? ''), basename($file).' needs alt text');
            // Only the two hero images load straight away; the rest wait until they're scrolled to.
            $this->assertSame($i >= 2, str_contains($tag, 'loading="lazy"'), basename($file));
        }
    }

    public function test_signed_in_people_are_offered_their_studios(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->get('/')->assertOk()->assertSee('Go to my studios')->assertDontSee('See how it works');
    }

    public function test_the_footer_has_a_contact_address(): void
    {
        $this->get('/pricing')->assertOk()->assertSee('<a href="mailto:hello@loftstudio.app">Contact</a>', false);
    }

    public function test_the_dashboard_setup_card_gives_way_to_the_schedule_link_once_everything_is_done(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_done', 'stripe_charges_enabled' => true]);
        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner);
        $url = route('studio.schedule', ['studio' => $studio]);
        $link = preg_replace('#^https?://#', '', $url); // shown without https://, so it fits the card
        $this->assertStringStartsWith('localhost', $link);

        $this->get("/{$studio->slug}/admin")->assertOk()
            ->assertSee('Setup')
            ->assertSee('Publish your waiver')
            ->assertSee('<a href="'.$url.'"><code>'.$link.'</code></a>', false);

        $this->waiver($studio);
        $studio->seasons()->create(['name' => 'Season', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString(), 'is_current' => true]);
        $this->program($studio);
        $this->plan($studio, ['stripe_price_id' => 'price_done']);
        $studio->imports()->create(['user_id' => $owner->id, 'kind' => 'people', 'status' => 'done', 'file_name' => 'clients.csv', 'csv' => '', 'row_count' => 1]);

        $this->get("/{$studio->slug}/admin")->assertOk()
            ->assertSee('Your schedule')
            ->assertSee('Members book, sign and pay from it.')
            ->assertDontSee('Publish your waiver')
            ->assertSee('<code>'.$link.'</code>', false);
    }
}
