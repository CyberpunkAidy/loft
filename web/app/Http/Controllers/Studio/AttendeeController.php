<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\PersonAnswers;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Family members on an account, and their answers to the studio's questions (decision 45). */
class AttendeeController extends Controller
{
    public function create(Request $request): View
    {
        return view('studio.attendee-form', ['attendee' => new Attendee, 'return' => ReturnPath::from($request)]);
    }

    public function store(Request $request, Studio $studio, PersonAnswers $answers): RedirectResponse
    {
        $attendee = $answers->save($studio->attendees()->make(['user_id' => $request->user()->id]), $this->validated($request, $studio), $request->user());

        return redirect(ReturnPath::from($request) ?? route('studio.account.family'))
            ->with('success', "{$attendee->first_name} has been added.");
    }

    public function edit(Request $request, Studio $studio, Attendee $attendee): View
    {
        $this->authorizeOwner($request, $attendee);

        return view('studio.attendee-form', ['attendee' => $attendee, 'return' => ReturnPath::from($request)]);
    }

    public function update(Request $request, Studio $studio, Attendee $attendee, PersonAnswers $answers): RedirectResponse
    {
        $this->authorizeOwner($request, $attendee);
        $answers->save($attendee, $this->validated($request, $studio), $request->user());

        return redirect(ReturnPath::from($request) ?? route('studio.account.family'))->with('success', 'Saved.');
    }

    /** Just the questions: on the way to a booking, for someone who hasn't answered them yet. */
    public function questions(Request $request, Studio $studio, Attendee $attendee): View|RedirectResponse
    {
        $this->authorizeOwner($request, $attendee);
        if (! Attendee::questionsAsked($studio)) {
            return redirect(ReturnPath::from($request) ?? route('studio.account.family'));
        }

        return view('studio.person-questions', ['attendee' => $attendee, 'return' => ReturnPath::from($request)]);
    }

    public function answer(Request $request, Studio $studio, Attendee $attendee, PersonAnswers $answers): RedirectResponse
    {
        $this->authorizeOwner($request, $attendee);
        $answers->save($attendee, $request->validate(Attendee::answerRules($studio, required: true), Attendee::answerMessages()), $request->user());

        return redirect(ReturnPath::from($request) ?? route('studio.account.family'))->with('success', 'Thanks, that’s saved. You can change it any time under Family.');
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

    /** The details, and an answer to each question the studio asks. */
    private function validated(Request $request, Studio $studio): array
    {
        $data = $request->validate([...Attendee::rules(), ...Attendee::answerRules($studio, required: true)], Attendee::answerMessages());
        $data['is_self'] = $request->boolean('is_self');

        return $data;
    }
}
