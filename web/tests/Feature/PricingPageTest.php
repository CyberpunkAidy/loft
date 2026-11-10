<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The pricing page (decision 40): monthly and yearly, the price lock, what's included, and what search sites read. */
class PricingPageTest extends TestCase
{
    public function test_the_pricing_page_offers_monthly_and_yearly_with_a_locked_price(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSee('<input type="radio" name="period" id="period-month" class="period-input" checked>', false)
            ->assertSee('<label for="period-year">Yearly <span class="save">2 months free</span></label>', false)
            ->assertSee('$49<small>/ month per studio</small>', false)
            ->assertSee('$490<small>/ year per studio</small>', false)
            ->assertSee('That’s $40.83 a month, billed yearly.', false)
            ->assertSee('30 days free, no card needed. Choose monthly or yearly when you subscribe.', false)
            ->assertSee('Your price is locked')
            ->assertSee('If Loft’s price goes up, the new price is only for studios that join after.', false)
            ->assertSeeInOrder(['Schedule and booking', 'Members and families', 'Payments and sales', 'Keeping members', 'Video', 'Getting started'])
            ->assertSee('An on-demand library, up to 10 hours per studio')
            ->assertSee('Month to month, or yearly with 2 months free')
            ->assertSee('On the yearly plan, Loft is $490 a year.')
            ->assertSeeInOrder(['data-out="now">$3,588', 'data-out="loft">$588', 'data-out="saved">$3,000'], false)
            ->assertSee('Is there a yearly plan?')
            ->assertSee('Yes: $490 a year, which is 2 months free. The saving is in the price; your 30-day free trial comes first either way.')
            ->assertSee('The price you start at is the price you keep on that plan.')
            ->assertSee('Each location with its own schedule is its own studio, at $49 a month each')
            ->assertSee('end of the month or year you’ve paid for', false)
            ->assertSee('Loft never adds a booking fee.')
            ->assertSee('mailto:hello@loftstudio.app', false);
    }

    public function test_without_a_yearly_plan_the_page_is_monthly_only(): void
    {
        config(['loft.billing.yearly_cents' => 0]);

        $this->get('/pricing')->assertOk()
            ->assertSee('$49<small>/ month per studio</small>', false)
            ->assertDontSee('period-year')
            ->assertDontSee('/ year per studio')
            ->assertDontSee('Is there a yearly plan?')
            ->assertDontSee('On the yearly plan')
            ->assertSee('30 days free, no card needed.</p>', false)
            ->assertSee('<td><svg', false)
            ->assertSee('Month to month</td>', false)
            ->assertSee('The price you start at is the price you keep. If Loft’s price changes', false)
            ->assertSee('end of the month you’ve paid for', false);
    }

    public function test_the_saving_is_described_from_the_prices(): void
    {
        config(['loft.billing.yearly_cents' => 50000]); // $588 less $500
        $this->get('/pricing')->assertOk()->assertSee('<span class="save">save $88</span>', false)->assertSee('$500<small>/ year per studio</small>', false);

        config(['loft.billing.yearly_cents' => 53900]); // eleven months
        $this->get('/pricing')->assertOk()->assertSee('<span class="save">1 month free</span>', false);

        config(['loft.billing.yearly_cents' => 60000]); // costs more than paying monthly: no saving claimed
        $this->get('/pricing')->assertOk()->assertSee('<label for="period-year">Yearly </label>', false)->assertDontSee('class="save"', false)
            ->assertSee('Month to month, or yearly with a discount');
    }

    public function test_a_loft_fee_on_sales_is_stated_wherever_it_matters(): void
    {
        config(['loft.stripe.platform_fee_percent' => 1.5]);

        $this->get('/pricing')->assertOk()
            ->assertSee('1.5% Loft fee on online sales')
            ->assertSee('1.5% on online sales</td>', false)
            ->assertSee('Loft adds 1.5% to online sales.')
            ->assertDontSee('No cut of your sales')
            ->assertDontSee('Loft takes nothing from your sales');
    }

    public function test_search_sites_get_both_prices_and_the_questions(): void
    {
        $html = $this->get('/pricing')->assertOk()
            ->assertSee('<meta name="description" content="Loft costs $49 a month per studio, or $490 a year, with every feature included', false)
            ->assertSee('<link rel="canonical" href="'.route('pricing').'">', false)
            ->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        [$app, $faq] = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['@graph'];
        $this->assertSame([['Monthly', '49.00', 'MON'], ['Yearly', '490.00', 'ANN']], array_map(fn ($o) => [$o['name'], $o['price'], $o['priceSpecification']['unitCode']], $app['offers']));
        $this->assertCount(9, $faq['mainEntity']);
        foreach ($faq['mainEntity'] as $q) {
            $this->assertStringContainsString('<summary>'.e($q['name']).'</summary>', $html);
        }

        config(['loft.billing.yearly_cents' => 0]);
        $html = $this->get('/pricing')->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        [$app, $faq] = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['@graph'];
        $this->assertSame(['Monthly'], array_column($app['offers'], 'name'));
        $this->assertCount(8, $faq['mainEntity']);
    }

    public function test_the_home_page_mentions_the_yearly_plan_and_the_lock(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Or $490 a year: 2 months free. 30-day free trial.')
            ->assertSee('The price you start at is the price you keep')
            ->assertSee('On the yearly plan, Loft is $490 a year.');

        config(['loft.billing.yearly_cents' => 0]);
        $this->get('/')->assertOk()->assertDontSee('a year:')->assertDontSee('On the yearly plan');
    }
}
