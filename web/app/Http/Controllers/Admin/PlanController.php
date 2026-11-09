<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Studio;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Plans & pricing, and creating their Stripe products/prices on the studio's account. */
class PlanController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.plans.index', [
            'plans' => $studio->plans()->with('season')
                ->withCount(['entitlements as holders_count' => fn ($q) => $q->whereIn('status', ['active', 'past_due'])])
                ->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function create(Studio $studio): View
    {
        return view('admin.plans.form', $this->formData($studio, new Plan(['kind' => 'subscription', 'active' => true, 'sort' => 0])));
    }

    public function store(Request $request, Studio $studio, StripeClient $stripe): RedirectResponse
    {
        $plan = $studio->plans()->create($this->validated($request, $studio));

        return $this->afterSave($studio, $plan, $stripe, 'Plan created.');
    }

    public function edit(Studio $studio, Plan $plan): View
    {
        return view('admin.plans.form', $this->formData($studio, $plan));
    }

    public function update(Request $request, Studio $studio, Plan $plan, StripeClient $stripe): RedirectResponse
    {
        $data = $this->validated($request, $studio);
        // Stripe prices can't change, so a new price (or kind) means a new Stripe price on the next sync.
        if ($data['price_cents'] !== $plan->price_cents || $data['kind'] !== $plan->kind) {
            $data['stripe_price_id'] = null;
        }
        if ($data['registration_fee_cents'] !== $plan->registration_fee_cents) {
            $data['stripe_fee_price_id'] = null;
        }
        $plan->update($data);

        return $this->afterSave($studio, $plan, $stripe, 'Plan saved. Existing members keep what they bought.');
    }

    public function sync(Studio $studio, Plan $plan, StripeClient $stripe): RedirectResponse
    {
        try {
            $plan->update($stripe->syncPlan($studio, $plan));
        } catch (StripeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return back()->with('success', "“{$plan->name}” is connected to Stripe and ready to sell.");
    }

    public function destroy(Studio $studio, Plan $plan): RedirectResponse
    {
        if ($plan->entitlements()->exists()) {
            $plan->update(['active' => false]);

            return redirect()->route('studio.admin.plans.index')
                ->with('success', "Members have bought “{$plan->name}”, so it was hidden instead of deleted.");
        }
        $plan->delete();

        return redirect()->route('studio.admin.plans.index')->with('success', 'Plan deleted.');
    }

    /** Create the Stripe price right away when the studio is connected. */
    private function afterSave(Studio $studio, Plan $plan, StripeClient $stripe, string $message): RedirectResponse
    {
        $redirect = redirect()->route('studio.admin.plans.index');
        if ($studio->stripeReady() && $stripe->configured() && ! $plan->isStripeReady()) {
            try {
                $plan->update($stripe->syncPlan($studio, $plan));
                $message .= ' Connected to Stripe.';
            } catch (StripeException $e) {
                return $redirect->with('warning', $message.' Stripe said: '.$e->getMessage().' Use “Create in Stripe” to retry.');
            }
        }

        return $redirect->with('success', $message);
    }

    private function formData(Studio $studio, Plan $plan): array
    {
        return [
            'plan' => $plan,
            'seasons' => $studio->seasons()->orderByDesc('starts_on')->get(),
            'tags' => $studio->categories()->where('type', 'plan_tag')->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request, Studio $studio): array
    {
        $tagSlugs = $studio->categories()->where('type', 'plan_tag')->pluck('slug')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(array_keys(Plan::KINDS))],
            'price' => ['required', 'numeric', 'min:0.5', 'max:100000'],
            'credits' => ['nullable', 'required_if:kind,pack', 'integer', 'between:1,500'],
            'credit_validity_days' => ['nullable', 'integer', 'between:0,3650'],
            'weekly_limit' => ['nullable', 'integer', 'between:1,50'],
            'season_id' => ['nullable', 'required_if:kind,season', Rule::exists('seasons', 'id')->where('studio_id', $studio->id)],
            'registration_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'scope' => ['nullable', 'array'],
            'scope.*' => [Rule::in($tagSlugs)],
            'active' => ['nullable', 'boolean'],
            'includes_videos' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'between:-1000,1000'],
        ], [
            'credits.required_if' => 'How many classes are in the pack?',
            'season_id.required_if' => 'Pick the season this tuition covers.',
            'price.min' => 'Stripe’s minimum charge is $0.50.',
        ]);

        $kind = $data['kind'];

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'kind' => $kind,
            'price_cents' => Money::toCents($data['price']),
            'currency' => $studio->currency,
            'billing_interval' => in_array($kind, ['subscription', 'season'], true) ? 'month' : null,
            'credits' => match ($kind) {
                'pack' => (int) $data['credits'],
                'dropin' => 1,
                default => null,
            },
            'credit_validity_days' => in_array($kind, ['pack', 'dropin'], true) && isset($data['credit_validity_days']) ? (int) $data['credit_validity_days'] : null,
            'weekly_limit' => in_array($kind, ['subscription', 'season'], true) && isset($data['weekly_limit']) ? (int) $data['weekly_limit'] : null,
            'season_id' => $kind === 'season' ? (int) $data['season_id'] : null,
            'registration_fee_cents' => $kind === 'season' ? (int) Money::toCents($data['registration_fee'] ?? 0) : 0,
            'program_scope' => $request->boolean('scope_all') || empty($data['scope']) ? null : array_values($data['scope']),
            'active' => $request->boolean('active'),
            'includes_videos' => $request->boolean('includes_videos'),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}
