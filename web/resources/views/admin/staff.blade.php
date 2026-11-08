@extends('layouts.admin')

@section('title', 'Staff')

@section('content')
<div class="page-head">
    <div>
        <h1>Staff</h1>
        <p>Instructors can use check-in. Admins can manage everything. Owners can also make other owners, and can’t be removed.</p>
    </div>
</div>
<div class="split">
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Name</th><th>Role</th><th></th></tr></thead>
            <tbody>
            @foreach ($staff as $s)
                <tr>
                    <td><strong>{{ $s->user->name }}</strong><div class="muted small">{{ $s->user->email }}</div></td>
                    <td>
                        @if ($s->role === 'owner')
                            Owner
                        @else
                            <form method="post" action="{{ route('studio.admin.staff.update', ['studioUser' => $s]) }}" class="row">
                                @csrf @method('patch')
                                <select class="input" name="role" aria-label="Role" style="width:auto" onchange="this.form.submit()">
                                    <option value="instructor" @selected($s->role === 'instructor')>Instructor</option>
                                    <option value="admin" @selected($s->role === 'admin')>Admin</option>
                                </select>
                                <noscript><button class="btn btn-small btn-secondary">Save</button></noscript>
                            </form>
                        @endif
                    </td>
                    <td>
                        <div class="actions">
                            @if ($s->role !== 'owner' && auth()->user()->hasRoleIn($studio, 'owner'))
                                <form method="post" action="{{ route('studio.admin.staff.update', ['studioUser' => $s]) }}" class="inline" data-confirm="Make {{ $s->user->first_name }} an owner? Owners have full access and can’t be removed.">
                                    @csrf @method('patch')
                                    <input type="hidden" name="role" value="owner">
                                    <button class="link">Make owner</button>
                                </form>
                            @endif
                            @if ($s->role !== 'owner' && $s->user_id !== auth()->id())
                                <form method="post" action="{{ route('studio.admin.staff.destroy', ['studioUser' => $s]) }}" class="inline" data-confirm="Remove {{ $s->user->first_name }}’s staff access? They stay a member.">
                                    @csrf @method('delete')
                                    <button class="link link-danger">Remove</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <aside class="card">
        <h3>Add staff</h3>
        <form method="post" action="{{ route('studio.admin.staff.store') }}" class="form">
            @csrf
            <x-field name="email" label="Email" type="email" required />
            <div class="grid-2">
                <x-field name="first_name" label="First name" />
                <x-field name="last_name" label="Last name" />
            </div>
            <small class="hint muted">Name only needed if they don’t have an account yet — we’ll email them a link to set a password.</small>
            <x-select name="role" label="Role" :options="['instructor' => 'Instructor', 'admin' => 'Admin']" value="instructor" />
            <button class="btn btn-small">Add</button>
        </form>
    </aside>
</div>
@endsection
