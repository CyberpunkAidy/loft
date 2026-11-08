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
    @if ($soleOwnerOf->isNotEmpty())
        <p>You’re the only owner of {{ $soleOwnerOf->pluck('name')->join(', ', ' and ') }}. To delete your account, first make another staff member an owner under Admin → Staff.</p>
    @else
        <p>This deletes your account at every studio you belong to on {{ config('app.name') }}, with your family members, bookings, passes and signed waivers. It can’t be undone.</p>
        <p>Memberships that renew are cancelled now, so you won’t be charged again. Payments you’ve already made aren’t refunded.</p>
        <form method="post" action="{{ route('studio.account.destroy') }}" class="form" data-confirm="Delete your account? This can’t be undone.">
            @csrf
            @method('delete')
            <x-field name="delete_password" label="Your password" type="password" autocomplete="current-password" required />
            <div class="actions"><button class="btn btn-danger">Delete my account</button></div>
        </form>
    @endif
</section>
@endsection
