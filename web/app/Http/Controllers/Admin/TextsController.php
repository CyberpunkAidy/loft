<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Listeners\StudioTexts;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Texts;
use App\Services\Twilio\TwilioClient;
use App\Services\Twilio\TwilioException;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Text messages (decision 48): connect the studio's own Twilio account, choose the number to
 * text from (Loft points its incoming texts at TwilioWebhookController), choose which booking
 * messages also go by text, send a test, and see what went wrong.
 */
class TextsController extends Controller
{
    public function show(Studio $studio, TwilioClient $twilio): View
    {
        $numbers = null;
        $twilioError = null;
        if ($studio->twilio_account_sid && ! $studio->sms_from && ! $studio->isDemo()) {
            try {
                $numbers = $twilio->smsNumbers((string) $studio->twilio_account_sid, (string) $studio->twilio_auth_token);
            } catch (TwilioException $e) {
                $twilioError = $e->getMessage();
            }
        }

        $monthStart = $studio->parseLocal($studio->localNow()->startOfMonth()->toDateTimeString());
        $texts = MessageDelivery::where('studio_id', $studio->id)->where('channel', 'sms');
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member');

        return view('admin.texts', [
            'numbers' => $numbers,
            'twilioError' => $twilioError,
            'month' => (clone $texts)->where('created_at', '>=', $monthStart)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'problems' => (clone $texts)->with('user')->where('status', 'failed')->latest('id')->limit(5)->get(),
            'optedIn' => [
                'updates' => (clone $members)->whereNotNull('sms_updates_at')->whereNull('sms_stopped_at')->count(),
                'marketing' => (clone $members)->whereNotNull('sms_marketing_at')->whereNull('sms_stopped_at')->count(),
                'stopped' => (clone $members)->whereNotNull('sms_stopped_at')->count(),
                'members' => $members->count(),
            ],
            'samples' => array_map(fn ($text) => Texts::plain($text), StudioTexts::samples($studio)),
        ]);
    }

    /** Check the Account SID and Auth Token with Twilio, save them, and take the number if there's only one. */
    public function connect(Request $request, Studio $studio, TwilioClient $twilio): RedirectResponse
    {
        $data = $request->validate([
            'account_sid' => ['required', 'string', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'auth_token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{32}$/'],
        ], [
            'account_sid.regex' => 'An Account SID starts with AC, then 32 letters and numbers. Copy it from your Twilio console’s home page.',
            'auth_token.regex' => 'An Auth Token is 32 letters and numbers. Copy it from your Twilio console’s home page (press Show).',
        ], ['account_sid' => 'Account SID', 'auth_token' => 'Auth Token']);
        $sid = $data['account_sid'];
        $token = strtolower($data['auth_token']);

        try {
            $account = $twilio->account($sid, $token);
        } catch (TwilioException $e) {
            return back()->withInput($request->except('auth_token'))->withErrors(['auth_token' => $e->httpStatus === 401 || $e->twilioCode === 20003
                ? 'Twilio didn’t accept that Account SID and Auth Token. Copy both again from your Twilio console.'
                : 'Couldn’t check them with Twilio: '.$e->getMessage()]);
        }
        if (($account['status'] ?? 'active') !== 'active') {
            return back()->withInput($request->except('auth_token'))->withErrors(['account_sid' => 'That Twilio account is '.$account['status'].'. Use an active account.']);
        }

        $studio->update(['twilio_account_sid' => $sid, 'twilio_auth_token' => $token, 'sms_from' => null, 'sms_number_sid' => null, 'sms_connected_at' => null]);
        try {
            $numbers = $twilio->smsNumbers($sid, $token);
        } catch (TwilioException) {
            $numbers = [];
        }
        if (count($numbers) === 1) {
            return $this->useNumber($studio, $twilio, $numbers[0]);
        }

        return redirect()->route('studio.admin.texts')->with('success', 'Your Twilio account is connected. Now choose the number to text from.');
    }

    public function number(Request $request, Studio $studio, TwilioClient $twilio): RedirectResponse
    {
        abort_unless($studio->twilio_account_sid !== null, 404);
        $data = $request->validate(['number_sid' => ['required', 'string', 'max:64']], ['number_sid.required' => 'Choose a number.']);
        try {
            $number = collect($twilio->smsNumbers((string) $studio->twilio_account_sid, (string) $studio->twilio_auth_token))->firstWhere('sid', $data['number_sid']);
        } catch (TwilioException $e) {
            return back()->with('error', 'Couldn’t reach Twilio: '.$e->getMessage());
        }
        if (! $number) {
            return back()->withErrors(['number_sid' => 'That number isn’t in your Twilio account any more. Reload and choose again.']);
        }

        return $this->useNumber($studio, $twilio, $number);
    }

    public function settings(Request $request, Studio $studio): RedirectResponse
    {
        $settings = $studio->settings ?? [];
        foreach (array_keys(Studio::TEXT_SETTINGS) as $key) {
            $settings[$key] = $request->boolean($key);
        }
        $studio->update(['settings' => $settings]);

        return back()->with('success', 'Saved.');
    }

    public function test(Request $request, Studio $studio, Texts $texts): RedirectResponse
    {
        abort_unless($studio->textsReady(), 404);
        $data = $request->validate(['test_phone' => ['required', 'string', 'max:40']], [], ['test_phone' => 'mobile number']);
        $phone = Phone::e164($data['test_phone']);
        if (! $phone) {
            return back()->withInput()->withErrors(['test_phone' => 'Enter a mobile number, like (303) 555-0123, or one with its + country code.']);
        }
        $texts->test($studio, $phone);

        return back()->with('success', 'A test text is on its way to '.Phone::display($phone).'. If it doesn’t arrive, the reason shows under Problems on this page within a minute or two.');
    }

    /** Stop texting. Members' choices are kept for if the studio connects again. */
    public function destroy(Studio $studio): RedirectResponse
    {
        $studio->update(['twilio_account_sid' => null, 'twilio_auth_token' => null, 'sms_from' => null, 'sms_number_sid' => null, 'sms_connected_at' => null]);

        return redirect()->route('studio.admin.texts')->with('success', 'Texts are off, and Loft has forgotten your Twilio keys. Members’ choices are kept in case you connect again. Texts to your number still come to Loft until you change where it sends them in Twilio.');
    }

    /** @param  array{sid: string, phone_number: string}  $number */
    private function useNumber(Studio $studio, TwilioClient $twilio, array $number): RedirectResponse
    {
        try {
            $twilio->pointSmsAt((string) $studio->twilio_account_sid, (string) $studio->twilio_auth_token, $number['sid'], route('twilio.incoming', ['studioId' => $studio->id]));
        } catch (TwilioException $e) {
            return redirect()->route('studio.admin.texts')->with('error', 'Twilio wouldn’t send replies to '.Phone::display($number['phone_number']).' to Loft: '.$e->getMessage());
        }
        $studio->update(['sms_from' => $number['phone_number'], 'sms_number_sid' => $number['sid'], 'sms_connected_at' => now()]);

        return redirect()->route('studio.admin.texts')->with('success', 'Texts are on. They come from '.Phone::display($number['phone_number']).'. Send yourself a test below.');
    }
}
