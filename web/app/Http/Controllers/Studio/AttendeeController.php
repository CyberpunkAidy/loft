<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Family members on an account. */
class AttendeeController extends Controller
{
    public function create(Request $request): View
    {
        return view('studio.attendee-form', ['attendee' => new Attendee, 'return' => ReturnPath::from($request)]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $attendee = $studio->attendees()->create($this->validated($request) + ['user_id' => $request->user()->id]);

        return redirect(ReturnPath::from($request) ?? route('studio.account.family'))
            ->with('success', "{$attendee->first_name} has been added.");
    }

    public function edit(Request $request, Studio $studio, Attendee $attendee): View
    {
        $this->authorizeOwner($request, $attendee);

        return view('studio.attendee-form', ['attendee' => $attendee, 'return' => ReturnPath::from($request)]);
    }

    public function update(Request $request, Studio $studio, Attendee $attendee): RedirectResponse
    {
        $this->authorizeOwner($request, $attendee);
        $attendee->update($this->validated($request));

        return redirect(ReturnPath::from($request) ?? route('studio.account.family'))->with('success', 'Saved.');
    }

    public function archive(Request $request, Studio $studio, Attendee $attendee): RedirectResponse
    {
        $this->authorizeOwner($request, $attendee);
        $attendee->update(['status' => 'archived']);

        return redirect()->route('studio.account.family')->with('success', "{$attendee->first_name} has been removed from your account.");
    }

    private function authorizeOwner(Request $request, Attendee $attendee): void
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'dob' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'emergency_contact' => ['nullable', 'string', 'max:190'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'is_self' => ['nullable', 'boolean'],
        ]);
        $data['is_self'] = $request->boolean('is_self');

        return $data;
    }
}
