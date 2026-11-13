@extends('layouts.studio')

@section('title', 'Profile')

@section('content')
@include('studio.account._tabs')

<form method="post" action="{{ route('studio.account.profile') }}" class="form narrow">
    @csrf
    @method('put')
    <div class="grid-2">
        <x-field name="first_name" label="First name" :value="$user->first_name" required />
        <x-field name="last_name" label="Last name" :value="$user->last_name" required />
    </div>
    <x-field name="email" label="Email" type="email" :value="$user->email" required />
    <x-field name="phone" label="Mobile phone" type="tel" :value="$user->phone" />
    <fieldset class="fieldset form">
        <legend>Emails from {{ $studio->name }}</legend>
        <x-checkbox name="marketing_email" :label="'News and offers from '.$studio->name" :checked="$marketingEmail" hint="Emails about your bookings and payments come either way." />
    </fieldset>
    @if ($studio->textsReady())
        @include('studio._text-consent', ['membership' => $membership])
    @endif
    <fieldset class="fieldset form">
        <legend>Change password</legend>
        <x-field name="current_password" label="Current password" type="password" autocomplete="current-password" />
        <div class="grid-2">
            <x-field name="password" label="New password" type="password" autocomplete="new-password" />
            <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" />
        </div>
    </fieldset>
    <div class="actions"><button class="btn">Save</button></div>
    <p class="small muted">Your profile is shared by every studio you belong to on {{ config('app.name') }}.</p>
</form>

<section class="fieldset form narrow delete-account" id="delete-account" aria-labelledby="delete-account-title">
    <h2 id="delete-account-title">Delete account</h2>
    <p>This deletes your account at every studio you belong to on {{ config('app.name') }}, with your family members, bookings and passes. It can’t be undone. Waivers you’ve signed stay with each studio as its record of what was agreed.</p>
    <p>Memberships that renew are cancelled now, so you won’t be charged again. Payments you’ve already made aren’t refunded.</p>
    <form method="post" action="{{ route('studio.account.destroy') }}" class="form" data-confirm="Delete your account? This can’t be undone.">
        @csrf
        @method('delete')
        @foreach ($ownedStudios as ['studio' => $owned, 'successors' => $successors])
            <x-select
                name="studios[{{ $owned->id }}]"
                :label="'You’re the only owner of '.$owned->name.'. What happens to it?'"
                placeholder="Choose…"
                :options="$successors->mapWithKeys(fn ($s) => [$s->user_id => 'Make '.$s->user->name.' the owner ('.$s->role.')'])->put('close', 'Close '.$owned->name.' and delete it')->all()"
                hint="Closing deletes the studio’s classes, plans, members’ bookings, passes and signed waivers, and cancels members’ renewing memberships. Download its signed waivers first (Admin → Waivers). Members keep their accounts, and your Stripe account stays yours."
                required
            />
        @endforeach
        <x-field name="delete_password" label="Your password" type="password" autocomplete="current-password" required />
        <div class="actions"><button class="btn btn-danger">Delete my account</button></div>
    </form>
</section>
@endsection
