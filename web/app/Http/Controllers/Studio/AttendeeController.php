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
        $data = $request->validate(Attendee::rules());
        $data['is_self'] = $request->boolean('is_self');

        return $data;
    }
}
