@extends('layouts.admin')

@section('title', $plan->exists ? $plan->name : 'Add a plan')

@section('content')
@php
    $scope = old('scope', $plan->program_scope ?? []);
    $scopeAll = old('scope_all', $plan->exists ? $plan->coversAll() : true);
@endphp
<p class="small"><a href="{{ route('studio.admin.plans.index') }}">‹ Plans &amp; pricing</a></p>
<div class="page-head">
    <h1>{{ $plan->exists ? $plan->name : 'Add a plan' }}</h1>
    @if ($plan->exists)
        <form method="post" action="{{ route('studio.admin.plans.destroy', ['plan' => $plan]) }}" class="inline" data-confirm="Delete this plan? If members bought it, it’s hidden instead.">
            @csrf @method('delete')
            <button class="btn btn-danger btn-small">Delete</button>
        </form>
    @endif
</div>
<form method="post" action="{{ $plan->exists ? route('studio.admin.plans.update', ['plan' => $plan]) : route('studio.admin.plans.store') }}" class="form" style="max-width: 720px">
    @csrf
    @if ($plan->exists) @method('put') @endif
    <fieldset class="fieldset form">
        <legend>Plan</legend>
        <x-field name="name" label="Name" :value="$plan->name" required placeholder="Monthly unlimited" />
        <x-select name="kind" label="Kind" :options="[
            'subscription' => 'Monthly membership (recurring)',
            'season' => 'Seasonal tuition (monthly until the season ends)',
            'pack' => 'Class pack (credits)',
            'dropin' => 'Single drop-in',
        ]" :value="$plan->kind" />
        <x-money name="price" label="Price" :value="\App\Support\Money::toAmount($plan->price_cents)" :currency="$studio->currency" required hint="Per month for memberships and tuition; the total for packs and drop-ins." />
        <x-textarea name="description" label="Description" :value="$plan->description" rows="2" />
    </fieldset>

    <fieldset class="fieldset form" data-show-when="kind=subscription,season">
        <legend>Membership</legend>
        <x-field name="weekly_limit" label="Classes per week" type="number" min="1" max="50" :value="$plan->weekly_limit" hint="Leave blank for unlimited." />
        <div data-show-when="kind=season" class="form">
            <x-select name="season_id" label="Season" :options="$seasons->pluck('name', 'id')->all()" :value="$plan->season_id" placeholder="Choose a season" hint="Billing stops automatically when the season ends." />
            <x-money name="registration_fee" label="Registration fee (one time)" :value="\App\Support\Money::toAmount($plan->registration_fee_cents ?: null)" :currency="$studio->currency" />
        </div>
    </fieldset>

    <fieldset class="fieldset form" data-show-when="kind=pack,dropin">
        <legend>Credits</legend>
        <div data-show-when="kind=pack">
            <x-field name="credits" label="Classes in the pack" type="number" min="1" max="500" :value="$plan->credits" />
        </div>
        <x-field name="credit_validity_days" label="Valid for (days)" type="number" min="0" max="3650" :value="$plan->credit_validity_days" :hint="'Blank uses the studio default ('.(int) $studio->setting('credit_validity_days').' days); 0 never expires.'" />
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Covers</legend>
        <label class="check"><input type="hidden" name="scope_all" value="0"><input type="checkbox" name="scope_all" value="1" @checked($scopeAll)> <span>Every program</span></label>
        <div class="checks">
            @forelse ($tags as $tag)
                <label class="check"><input type="checkbox" name="scope[]" value="{{ $tag->slug }}" @checked(in_array($tag->slug, (array) $scope))> <span>Programs tagged <strong>{{ $tag->name }}</strong></span></label>
            @empty
                <span class="muted small">No plan tags yet — add them on a program.</span>
            @endforelse
        </div>
        <small class="hint">Untick “Every program” and pick tags to limit what this plan can book.</small>
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Visibility</legend>
        <x-checkbox name="active" label="Members can buy this" :checked="$plan->active" />
        <x-field name="sort" label="Sort order" type="number" :value="$plan->sort" hint="Lower numbers show first." />
    </fieldset>

    <div class="actions"><button class="btn">{{ $plan->exists ? 'Save plan' : 'Create plan' }}</button></div>
    @if ($plan->exists && $plan->isStripeReady())
        <p class="small muted">Changing the price creates a new Stripe price for new buyers; current subscribers keep their price.</p>
    @endif
</form>
@endsection
