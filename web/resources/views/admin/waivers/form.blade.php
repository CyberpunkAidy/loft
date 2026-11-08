@extends('layouts.admin')

@section('title', $waiver->exists ? $waiver->title : 'New waiver')

@section('content')
@php $chosen = collect(old('program_ids', $waiver->exists ? $waiver->programs->pluck('id')->all() : []))->map(fn ($id) => (int) $id); @endphp
<p class="small"><a href="{{ route('studio.admin.waivers.index') }}">‹ Waivers</a></p>
<h1>{{ $waiver->exists ? $waiver->title : 'New waiver' }}</h1>
<form method="post" action="{{ $waiver->exists ? route('studio.admin.waivers.update', ['waiver' => $waiver]) : route('studio.admin.waivers.store') }}" class="form" style="max-width: 760px">
    @csrf
    @if ($waiver->exists) @method('put') @endif
    <x-field name="title" label="Title" :value="$waiver->title" required placeholder="Release of liability" />
    <x-textarea name="body" label="Waiver text" :value="$waiver->body" rows="16" required
        hint="Plain text. Leave a blank line between paragraphs. Have your lawyer review it. Once anyone has signed, changing the text publishes a new version and everyone signs again." />

    <fieldset class="fieldset form">
        <legend>Who signs it</legend>
        <x-select name="scope" label="Required for" :options="\App\Models\Waiver::SCOPES" :value="$waiver->scope" />
        <div class="field @error('program_ids') has-error @enderror" data-show-when="scope=program">
            <span>Classes</span>
            @if ($programs->isEmpty())
                <small class="hint">Add a class under Programs first.</small>
            @else
                <div class="checks">
                    @foreach ($programs as $program)
                        <label class="check"><input type="checkbox" name="program_ids[]" value="{{ $program->id }}" @checked($chosen->contains($program->id))> <span>{{ $program->title }}</span></label>
                    @endforeach
                </div>
            @endif
            <small class="hint">For example, an aerial liability waiver only for the aerial classes. Waivers for every class are needed too.</small>
            @error('program_ids')<small class="error">{{ $message }}</small>@enderror
        </div>
        <x-field name="validity_days" label="Signature lasts (days)" type="number" min="1" max="3650" :value="$waiver->validity_days"
            :hint="'Leave blank to use your studio setting ('.((int) $studio->setting('waiver_validity_days') ?: 'no expiry').((int) $studio->setting('waiver_validity_days') ? ' days' : '').').'" />
    </fieldset>

    <x-checkbox name="is_active" label="In force" :checked="$waiver->is_active" hint="Turned off, nobody needs to sign it." />
    @if ($waiver->exists)
        <x-checkbox name="new_version" :label="'Publish as version '.($waiver->version + 1).' even if the text is unchanged'" hint="Everyone signs again before their next class." />
    @endif
    <div class="actions"><button class="btn">Save waiver</button></div>
</form>
@endsection
