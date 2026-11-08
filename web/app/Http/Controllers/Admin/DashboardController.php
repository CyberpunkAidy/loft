<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StripeEvent;
use App\Models\Studio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Studio $studio): View|RedirectResponse
    {
        if (! $request->user()->hasRoleIn($studio, 'admin')) {
            return redirect()->route('studio.admin.checkin'); // instructors live on the check-in screen
        }

        $today = $studio->localNow()->startOfDay();
        $todaySessions = $studio->sessions()->withSeatCounts()->with('program')
            ->where('starts_at', '>=', $today->copy()->utc())
            ->where('starts_at', '<', $today->copy()->addDay()->utc())
            ->orderBy('starts_at')->get();

        $hasPlans = $studio->plans()->exists();
        $checklist = [
            ['done' => $studio->stripeReady(), 'label' => 'Connect Stripe so members can pay', 'url' => route('studio.admin.payments')],
            ['done' => $studio->activeWaivers()->isNotEmpty(), 'label' => 'Publish your waiver', 'url' => route('studio.admin.waivers.index')],
            ['done' => $studio->seasons()->exists(), 'label' => 'Add a season (e.g. Sept → May)', 'url' => route('studio.admin.seasons.index')],
            ['done' => $studio->programs()->exists(), 'label' => 'Add your classes', 'url' => route('studio.admin.programs.index')],
            ['done' => $hasPlans && ! $studio->plans()->where('active', true)->whereNull('stripe_price_id')->exists(), 'label' => 'Create plans and connect them to Stripe', 'url' => route('studio.admin.plans.index')],
        ];

        return view('admin.dashboard', [
            'todaySessions' => $todaySessions,
            'checklist' => $checklist,
            'stats' => [
                'members' => $studio->studioUsers()->where('role', 'member')->count(),
                'sales_month' => (int) $studio->orders()->where('status', 'paid')->where('currency', $studio->currency)
                    ->where('paid_at', '>=', $studio->localNow()->startOfMonth()->utc())->sum('total_cents'),
                'memberships' => $studio->entitlements()->where('kind', 'subscription')->where('status', 'active')->count(),
                'past_due' => $studio->entitlements()->where('status', 'past_due')->count(),
                'bookings_week' => $studio->bookings()->where('booked_at', '>=', now()->subDays(7))->whereNotIn('status', ['cancelled'])->count(),
                'waitlisted' => $studio->bookings()->where('status', 'waitlisted')
                    ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>', now()))->count(),
            ],
            'webhookErrors' => StripeEvent::where('studio_id', $studio->id)->whereNull('processed_at')->whereNotNull('error')->count(),
        ]);
    }
}
