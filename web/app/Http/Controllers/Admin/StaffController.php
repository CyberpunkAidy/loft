<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Who can run the studio. Instructors get check-in; admins get everything; owners can't be removed,
 * and only an owner can make someone else an owner (so the studio can change hands).
 */
class StaffController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.staff', [
            'staff' => $studio->studioUsers()->with('user')->whereIn('role', StudioUser::STAFF_ROLES)->get()
                ->sortByDesc(fn ($s) => StudioUser::rank($s->role))->values(),
        ]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(['instructor', 'admin'])],
        ]);
        $email = strtolower($data['email']);
        $user = User::where('email', $email)->first();
        $invited = false;
        if (! $user) {
            $request->validate(['first_name' => ['required'], 'last_name' => ['required']], [
                'first_name.required' => 'They don’t have an account yet. Add their name and we’ll email them a link to set a password.',
            ]);
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $email,
                'password' => Str::random(40),
            ] + User::madeIn($studio));
            if (! $studio->isDemo()) { // a demo emails nobody
                Password::sendResetLink(['email' => $email]);
            }
            $invited = true;
        }

        $membership = $studio->studioUsers()->firstOrNew(['user_id' => $user->id]);
        if ($membership->exists && $membership->role === 'owner') {
            return back()->with('error', "{$user->name} is an owner already.");
        }
        $membership->role = $data['role'];
        $membership->save();

        return back()->with('success', "{$user->name} is now ".($data['role'] === 'admin' ? 'an admin' : 'an instructor').'.'
            .($invited ? ' We emailed them a link to set their password.' : ''));
    }

    public function update(Request $request, Studio $studio, StudioUser $studioUser): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['instructor', 'admin', 'owner'])]]);
        if ($studioUser->role === 'owner') {
            return back()->with('error', 'Owners keep full access.');
        }
        if ($data['role'] === 'owner') {
            abort_unless($request->user()->hasRoleIn($studio, 'owner'), 403);
            $studioUser->update(['role' => 'owner']);

            return back()->with('success', "{$studioUser->user->name} is now an owner.");
        }
        $studioUser->update(['role' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    /** Remove staff access; they stay a member, so their family and bookings are untouched. */
    public function destroy(Request $request, Studio $studio, StudioUser $studioUser): RedirectResponse
    {
        if ($studioUser->role === 'owner') {
            return back()->with('error', 'Owners can’t be removed.');
        }
        $studioUser->update(['role' => 'member']);

        return back()->with('success', 'Staff access removed.');
    }
}
