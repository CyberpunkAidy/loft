<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Automations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin → Automations: the emails that go out by themselves. */
class AutomationController extends Controller
{
    public function index(Studio $studio): View
    {
        $automations = collect(array_keys(Automation::TRIGGERS))->mapWithKeys(fn ($trigger) => [$trigger => Automation::for($studio, $trigger)]);
        $sent = MessageDelivery::where('studio_id', $studio->id)->whereIn('automation_id', $automations->pluck('id')->filter())
            ->where('status', 'sent')->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('automation_id, count(*) as n')->groupBy('automation_id')->pluck('n', 'automation_id');
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member');

        return view('admin.automations.index', [
            'automations' => $automations,
            'sent' => $sent,
            'members' => (clone $members)->count(),
            'optedIn' => $members->whereNotNull('email_marketing_consent_at')->whereNull('email_unsubscribed_at')->count(),
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
        ]);
    }

    public function update(Request $request, Studio $studio, string $trigger): RedirectResponse
    {
        $automation = Automation::for($studio, $trigger);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'delay_hours' => [$trigger === 'first_class' ? 'required' : 'nullable', 'integer', 'between:0,168'],
            'days' => [$trigger === 'win_back' ? 'required' : 'nullable', 'integer', 'between:7,365'],
            'active' => ['nullable', 'boolean'],
        ], [], ['delay_hours' => 'hours after the class', 'days' => 'days since their last class']);

        $errors = [];
        foreach (['subject', 'body'] as $field) {
            if ($unknown = $automation->unknownPlaceholders($data[$field])) {
                $errors[$field] = 'Unknown placeholder '.implode(', ', array_map(fn ($p) => '{'.$p.'}', $unknown))
                    .'. You can use '.implode(', ', array_map(fn ($p) => '{'.$p.'}', $automation->placeholders())).'.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $automation->fill([
            'subject' => $data['subject'],
            'body' => $data['body'],
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
        $automations->sendTest($automation, $request->user());

        return back()->with('success', "A test is on its way to {$request->user()->email}, with sample names filled in.");
    }
}
