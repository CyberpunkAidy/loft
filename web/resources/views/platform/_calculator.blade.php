{{-- The savings calculator (home and pricing pages): a year of a studio's current software, plus any
     fee it adds to card sales, against a year of Loft on the monthly plan. PlatformController works
     out the first answer so it reads right without JavaScript; public/js/app.js ([data-calc]) keeps
     the same sum as people type. Needs $calc, $monthly, $fee, $currency and $yearly. --}}
@php($money = fn ($amount) => str_replace('.00', '', \App\Support\Money::format((int) round($amount * 100), strtolower($currency))))
<form class="card calc" data-calc data-monthly="{{ $monthly }}" data-fee="{{ $fee }}" data-currency="{{ $currency }}">
    <h3><x-icon name="calculator" /> What do you pay now?</h3>
    <p class="small muted">Enter what your current software costs. It’s worked out here in your browser; nothing is sent anywhere.</p>
    <div class="calc-fields">
        <label class="calc-field"><span>Software a month</span>
            <span class="input-affix"><i>$</i><input type="number" name="software" min="0" step="1" inputmode="decimal" value="{{ $calc['software'] }}"></span></label>
        <label class="calc-field"><span>Its fee on sales</span>
            <span class="input-affix"><input type="number" name="percent" min="0" max="20" step="0.1" inputmode="decimal" value="{{ $calc['percent'] }}"><i>%</i></span></label>
        <label class="calc-field"><span>Card sales a month</span>
            <span class="input-affix"><i>$</i><input type="number" name="sales" min="0" step="100" inputmode="decimal" value="{{ $calc['sales'] }}"></span></label>
    </div>
    <dl class="calc-result" aria-live="polite">
        <div><dt>You pay now</dt><dd data-out="now">{{ $money($calc['now']) }}<small>/year</small></dd></div>
        <div><dt>With Loft</dt><dd data-out="loft">{{ $money($calc['loft']) }}<small>/year</small></dd></div>
        <div class="calc-saved"><dt data-out="saved-label">You’d keep</dt><dd data-out="saved">{{ $money(abs($calc['saved'])) }}<small>/year</small></dd></div>
    </dl>
    @if ($yearly)<p class="small muted calc-yearly">On the yearly plan, Loft is {{ $yearly }} a year.</p>@endif
    <p class="tiny muted">Only count fees your software adds on top of card processing. Stripe’s own card fees (in the US, 2.9% + 30¢ a charge) apply with Loft too, so they’re left out of both sides.</p>
</form>
