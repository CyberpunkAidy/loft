<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Automations;
use App\Services\Texts;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin → Automations: the emails and texts that go out by themselves. */
class AutomationController extends Controller
{
    public function index(Studio $studio): View
    {
        $automations = collect(array_keys(Automation::TRIGGERS))->mapWithKeys(fn ($trigger) => [$trigger => Automation::for($studio, $trigger)]);
        $sent = MessageDelivery::where('studio_id', $studio->id)->whereIn('automation_id', $automations->pluck('id')->filter())
            ->whereIn('status', ['sent', 'delivered'])->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('automation_id, count(*) as n')->groupBy('automation_id')->pluck('n', 'automation_id');
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member');

        return view('admin.automations.index', [
            'automations' => $automations,
            'sent' => $sent,
            'members' => (clone $members)->count(),
            'optedIn' => (clone $members)->whereNotNull('email_marketing_consent_at')->whereNull('email_unsubscribed_at')->count(),
            'textOptedIn' => $studio->textsReady() ? $members->whereNotNull('sms_marketing_at')->whereNull('sms_stopped_at')->count() : null,
        ]);
    }

    public function edit(Request $request, Studio $studio, string $trigger, Automations $automations): View
    {
        $automation = Automation::for($studio, $trigger)->setRelation('studio', $studio);
        // Plain values: a Mailable passed to a view would be rendered to HTML (it's Renderable).
        $message = $automations->message($automation, $request->user(), null, $automations->sampleValues($automation));

        return view('admin.automations.form', [
            'automation' => $automation,
            'sample' => ['headline' => $message->headline, 'lines' => $message->lines, 'action' => $message->actionLabel],
            'sampleText' => Texts::plain(Texts::compose($studio, $automations->text($automation, $request->user(), null, $automations->sampleValues($automation)), 'marketing')),
        ]);
    }

    public function update(Request $request, Studio $studio, string $trigger): RedirectResponse
    {
        $automation = Automation::for($studio, $trigger);
        $data = $request->validate([
            // Texts need Twilio; without it, an automation can only keep the channel it has, or go back to email.
            'channel' => ['nullable', Rule::in($studio->textsReady() ? array_keys(Automation::CHANNELS) : array_unique(['email', $automation->channel]))],
            'subject' => ['required_unless:channel,sms', 'nullable', 'string', 'max:150'],
            'body' => ['required_unless:channel,sms', 'nullable', 'string', 'max:5000'],
            'sms_body' => ['required_if:channel,sms,both', 'nullable', 'string', 'max:'.Automation::SMS_MAX],
            'delay_hours' => [$trigger === 'first_class' ? 'required' : 'nullable', 'integer', 'between:0,168'],
            'days' => [$trigger === 'win_back' ? 'required' : 'nullable', 'integer', 'between:7,365'],
            'active' => ['nullable', 'boolean'],
        ], [], ['delay_hours' => 'hours after the class', 'days' => 'days since their last class', 'sms_body' => 'text message', 'body' => 'message']);
        $channel = $data['channel'] ?? $automation->channel ?? 'email';

        $errors = [];
        foreach (['subject' => false, 'body' => false, 'sms_body' => true] as $field => $sms) {
            if (isset($data[$field]) && ($unknown = $automation->unknownPlaceholders($data[$field], $sms))) {
                $errors[$field] = 'Unknown placeholder '.implode(', ', array_map(fn ($p) => '{'.$p.'}', $unknown))
                    .'. You can use '.implode(', ', array_map(fn ($p) => '{'.$p.'}', $sms ? $automation->smsPlaceholders() : $automation->placeholders())).'.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $automation->fill([
            'channel' => $channel,
            'subject' => $data['subject'] ?? $automation->subject,
            'body' => $data['body'] ?? $automation->body,
            'sms_body' => $data['sms_body'] ?? $automation->sms_body,
            'delay_minutes' => $trigger === 'first_class' ? 60 * (int) $data['delay_hours'] : 0,
            'conditions' => $trigger === 'win_back' ? ['days' => (int) $data['days']] : null,
            'active' => $request->boolean('active'),
        ])->save();

        return redirect()->route('studio.admin.automations.edit', ['trigger' => $trigger])
            ->with('success', $automation->active ? 'Saved. It’s on.' : 'Saved. It’s off until you turn it on.');
    }

    public function test(Request $request, Studio $studio, string $trigger, Automations $automations): RedirectResponse
    {
        $automation = Automation::for($studio, $trigger)->setRelation('studio', $studio);
        $me = $request->user();
        $sent = $automations->sendTest($automation, $me);
        $to = array_filter([$automation->sendsEmail() ? $me->email : null, $sent['text'] ? Phone::display($me->phone_e164) : null]);
        $note = $automation->sendsText() && ! $sent['text']
            ? ($studio->textsReady() ? ' Add your mobile number to your profile to get the text too.' : ' Texts aren’t connected, so there’s no test text.')
            : '';

        return back()->with($to ? 'success' : 'warning', ($to ? 'A test is on its way to '.implode(' and ', $to).', with sample names filled in.' : 'Nothing was sent.').$note);
    }
}
