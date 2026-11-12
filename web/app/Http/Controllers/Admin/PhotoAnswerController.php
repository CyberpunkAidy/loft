<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\PersonAnswers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Staff record someone's photo answer from what they were told (decision 46), from check-in or the
 * member page. Each form has its own error bag, so a mistake shows on the right person's row.
 */
class PhotoAnswerController extends Controller
{
    public function store(Request $request, Studio $studio, Attendee $attendee, PersonAnswers $answers): RedirectResponse
    {
        abort_unless($attendee->status === 'active' && in_array('photos', Attendee::questionsAsked($studio), true), 404);
        $back = redirect()->to(url()->previous().'#photos-'.$attendee->id);
        $validator = Validator::make($request->all(), [
            'photo_consent' => ['required', Rule::in(array_keys(Attendee::PHOTO_CONSENT))],
            'photo_note' => ['required', 'string', 'max:190'],
        ], [
            'photo_consent.required' => 'Choose their answer.',
            'photo_note.required' => 'Say how they told you, for example “At the front desk”.',
        ]);
        if ($validator->fails()) {
            return $back->withErrors($validator, 'photos'.$attendee->id)->withInput();
        }
        $data = $validator->validated();

        if (! $answers->recordByStaff($attendee, $data['photo_consent'], trim($data['photo_note']), $request->user())) {
            return $back->with('success', "That was already {$attendee->first_name}’s answer.");
        }
        $who = $attendee->user?->first_name;

        return $back->with('success', "Recorded for {$attendee->first_name}: ".lcfirst(Attendee::PHOTO_CONSENT[$data['photo_consent']]).'.'
            .($who ? " We’ve emailed {$who} so they can check it." : ''));
    }
}
