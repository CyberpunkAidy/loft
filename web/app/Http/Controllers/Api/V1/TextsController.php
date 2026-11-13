<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Consent;
use App\Services\Texts;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The member's text choices at a studio (decision 48): booking texts, news and offers, and the number they go to. */
class TextsController extends Controller
{
    public function show(Request $request, Studio $studio): JsonResponse
    {
        return response()->json(['data' => $this->state($request, $studio)]);
    }

    /** {updates, marketing, phone?}: opting in needs a number we can text, and is confirmed by text. */
    public function update(Request $request, Studio $studio, Consent $consent, Texts $texts): JsonResponse
    {
        if (! $studio->textsReady()) {
            throw new ApiException('texts_unavailable', "{$studio->name} doesn’t send texts.");
        }
        $data = $request->validate([
            'updates' => ['required', 'boolean'],
            'marketing' => ['required', 'boolean'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        $membership = $this->membership($request, $studio);
        $wants = $request->boolean('updates') || $request->boolean('marketing');
        if ($wants && $membership->sms_stopped_at) {
            throw new ApiException('texts_stopped', "You replied STOP to {$studio->name}’s texts. To get them again, text START to {$studio->textsFrom()}.", 409, ['from' => $studio->textsFrom()]);
        }
        if ($wants) {
            Texts::assertTextable(array_key_exists('phone', $data) ? $data['phone'] : $user->phone);
        }
        if (array_key_exists('phone', $data)) {
            $user->update(['phone' => $data['phone']]); // a new number turns texts off everywhere, then this turns them back on here
        }
        $membership->refresh();
        $texts->confirm($membership, $consent->setTexts($membership, $request->boolean('updates'), $request->boolean('marketing'), 'app'));

        return response()->json(['data' => $this->state($request, $studio)]);
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Studio $studio): array
    {
        $user = $request->user();
        $membership = $this->membership($request, $studio);

        return [
            'available' => $studio->textsReady(),
            'from' => $studio->textsReady() ? $studio->textsFrom() : null,
            'phone' => $user->phone,
            'phone_textable' => $user->phone_e164 !== null,
            'updates' => $membership->wantsTextUpdates(),
            'marketing' => $membership->wantsTextMarketing(),
            'stopped_at' => $membership->sms_stopped_at?->toIso8601String(),
            'labels' => ['updates' => Texts::optInLabel($studio, 'updates'), 'marketing' => Texts::optInLabel($studio, 'marketing')],
            'disclosure' => Texts::DISCLOSURE,
            'privacy_url' => route('privacy').'#texts',
        ];
    }

    private function membership(Request $request, Studio $studio): StudioUser
    {
        return StudioUser::where('studio_id', $studio->id)->where('user_id', $request->user()->id)->firstOrFail();
    }
}
