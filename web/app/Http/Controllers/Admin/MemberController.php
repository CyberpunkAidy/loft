<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\MemberRiskScore;
use App\Models\MessageDelivery;
use App\Models\Payment;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\ChurnScores;
use App\Services\OfflinePayments;
use App\Services\Waivers;
use App\Support\Money;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Member accounts: families, plans, bookings, and giving a plan by hand (paid in person or comped). */
class MemberController extends Controller
{
    public function index(Request $request, Studio $studio, ChurnScores $churn): View
    {
        $q = (string) $request->query('q', '');
        $atRisk = $request->query('risk') === 'at_risk';
        $day = $churn->latestDay($studio);
        $members = $studio->members()
            ->tap(fn ($query) => Search::apply($query, ['first_name', 'last_name', 'email'], $q))
            // At risk: high and medium on the latest night scored, riskiest first (nobody before the first night).
            ->when($atRisk && ! $day, fn ($query) => $query->whereRaw('1 = 0'))
            ->when($atRisk && $day, fn ($query) => $query
                ->join('member_risk_scores as risk', fn ($join) => $join->on('risk.user_id', '=', 'users.id')
                    ->where('risk.studio_id', $studio->id)->whereDate('risk.as_of', $day)->where('risk.model_version', ChurnScores::MODEL))
                ->whereIn('risk.level', ['high', 'medium'])->orderByDesc('risk.score'))
            ->orderByPivot('created_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $ids = $members->pluck('id');
        $attendeeCounts = $studio->attendees()->where('status', 'active')->whereIn('user_id', $ids)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $planCounts = $studio->entitlements()->whereIn('status', ['active', 'past_due'])->whereIn('user_id', $ids)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $risk = $day ? MemberRiskScore::where('studio_id', $studio->id)->where('model_version', ChurnScores::MODEL)->whereDate('as_of', $day)
            ->whereIn('user_id', $ids)->get()->keyBy('user_id') : collect();

        return view('admin.members.index', compact('members', 'q', 'attendeeCounts', 'planCounts', 'risk', 'atRisk', 'day'));
    }

    public function show(Studio $studio, User $member, Waivers $waivers): View
    {
        $attendees = $studio->attendees()->where('user_id', $member->id)->orderBy('status')->orderBy('first_name')->get();

        return view('admin.members.show', [
            'member' => $member,
            'role' => $member->pivot->role,
            'attendees' => $attendees,
            'waiverStatus' => $attendees->mapWithKeys(fn ($a) => [$a->id => $waivers->statusFor($studio, $a)]),
            'risk' => MemberRiskScore::where('studio_id', $studio->id)->where('user_id', $member->id)->where('model_version', ChurnScores::MODEL)
                ->orderByDesc('as_of')->limit(8)->get(),
            'tags' => $studio->accessCredentials()->with('attendee')->whereIn('attendee_id', $attendees->pluck('id'))->whereNull('revoked_at')->orderBy('id')->get(),
            'entitlements' => $studio->entitlements()->with(['plan', 'payment'])->where('user_id', $member->id)->latest()->get(),
            'orders' => $studio->orders()->sales()->with(['items', 'payment', 'studio'])->where('user_id', $member->id)->latest('paid_at')->latest('id')->limit(25)->get(),
            'upcoming' => $studio->bookings()->with(['classSession.program', 'attendee'])->where('user_id', $member->id)
                ->whereIn('status', Booking::ACTIVE_STATUSES)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>', now()))
                ->get()->sortBy(fn ($b) => $b->classSession->starts_at),
            'recent' => $studio->bookings()->with(['classSession.program', 'attendee'])->where('user_id', $member->id)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '<=', now()))
                ->latest('booked_at')->limit(20)->get(),
            'plans' => $studio->plans()->orderBy('sort')->orderBy('name')->get(),
            'membership' => StudioUser::where('studio_id', $studio->id)->where('user_id', $member->id)->firstOrFail(),
            'activity' => DomainEvent::with('attendee')->where('studio_id', $studio->id)->where('user_id', $member->id)
                ->latest('occurred_at')->latest('id')->limit(40)->get(),
            'deliveries' => MessageDelivery::where('studio_id', $studio->id)->where('user_id', $member->id)->latest('id')->limit(15)->get(),
        ]);
    }

    /** Give a member a plan: paid in person (cash, check, card terminal…) or comped. */
    public function grant(Request $request, Studio $studio, User $member, OfflinePayments $payments): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('studio_id', $studio->id)],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $plan = $studio->plans()->findOrFail($data['plan_id']);
        $payment = $payments->record($plan, $member, $request->user(), [
            'method' => $data['method'],
            'amount_cents' => Money::toCents($data['amount'] ?? null),
            'paid_on' => $data['paid_on'] ?? $studio->localNow()->toDateString(),
            'ends_on' => $data['ends_on'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', $payment->isComp()
            ? "Gave “{$plan->name}” to {$member->name} at no charge."
            : "Recorded {$payment->amountLabel()} ({$payment->methodLabel()}) and added “{$plan->name}” for {$member->name}.");
    }
}
