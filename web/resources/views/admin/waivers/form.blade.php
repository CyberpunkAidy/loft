@extends('layouts.admin')

@section('title', $waiver->exists ? $waiver->title : 'New waiver')

@section('content')
<p class="small"><a href="{{ route('studio.admin.waivers.index') }}">‹ Waivers</a></p>
<h1>{{ $waiver->exists ? $waiver->title : 'New waiver' }}</h1>
<form method="post" action="{{ $waiver->exists ? route('studio.admin.waivers.update', ['waiver' => $waiver]) : route('studio.admin.waivers.store') }}" class="form" style="max-width: 760px">
    @csrf
    @if ($waiver->exists) @method('put') @endif
    <x-field name="title" label="Title" :value="$waiver->title" required placeholder="Release of liability" />
    <x-textarea name="body" label="Waiver text" :value="$waiver->body" rows="16" required hint="Plain text. Leave a blank line between paragraphs. Have your lawyer review it." />
    <x-checkbox name="is_active" label="This is the active waiver" :checked="$waiver->is_active" />
    @if ($waiver->exists)
        <x-checkbox name="new_version" :label="'Publish as version '.($waiver->version + 1)" hint="Everyone signs again before their next booking. Leave unticked for typo fixes." />
    @endif
    <div class="actions"><button class="btn">Save waiver</button></div>
</form>
@endsection
