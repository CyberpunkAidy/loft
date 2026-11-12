@extends('layouts.admin')

@section('title', $member->name)

@section('content')
<p class="small"><a href="{{ route('studio.admin.members.index') }}">‹ Members</a></p>
<div class="page-head">
    <div>
        <h1>{{ $member->name }}</h1>
        <p><a href="mailto:{{ $member->email }}">{{ $member->email }}</a>@if ($member->phone) · {{ $member->phone }}@endif · {{ \App\Models\StudioUser::ROLES[$role] ?? $role }}</p>
    </div>
</div>

<div class="split">
    <div class="stack">
        <section>
            <h2>Family</h2>
            @if ($attendees->isEmpty())
                <div class="card empty">No attendees on this account.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Age</th><th>Waiver</th><th>Photos</th><th>Health</th></tr></thead>
                        <tbody>
                        @foreach ($attendees as $a)
                            @php $w = $waiverStatus[$a->id]; @endphp
                            <tr @class(['is-cancelled' => $a->status === 'archived'])>
                                <td>{{ $a->name }}@if ($a->is_self) <span class="muted">(self)</span>@endif @if ($a->status === 'archived')<span class="badge badge-muted">Removed</span>@endif</td>
                                <td>{{ $a->age() ?? '—' }}</td>
                                <td>@if (! $w['required'])—@elseif ($w['signed'])<span class="badge">Signed</span>@else<span class="badge badge-full">Missing</span>@endif</td>
                                <td class="small">
                                    @if ($a->photo_consent)
                                        {{ \App\Models\Attendee::PHOTO_CONSENT_SHORT[$a->photo_consent] }}
                                        <div class="muted" title="{{ \App\Models\Attendee::PHOTO_CONSENT[$a->photo_consent] }}">Answered {{ $studio->local($a->photo_consent_at)->format('M j, Y') }}</div>
                                    @else
                                        <span class="muted">Not asked yet</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($a->medical_notes)<span class="medical">{{ $a->medical_notes }}</span>@elseif ($a->health_answered_at)None@else<span class="muted">Not asked yet</span>@endif
                                    @if ($a->health_answered_at)<div class="muted">Answered {{ $studio->local($a->health_answered_at)->format('M j, Y') }}</div>@endif
                                    @if ($a->emergency_contact)<div class="muted">Emergency: {{ $a->emergency_contact }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <h2>Check-in tags</h2>
            <div class="card">
                @if ($tags->isNotEmpty())
                    <div class="pos-lines" style="margin-bottom:1rem">
                        @foreach ($tags as $tag)
                            <div class="pos-line">
                                <div class="grow">{{ $tag->attendee->first_name }} · <strong>…{{ $tag->value_last4 }}</strong>@if ($tag->label) <span class="muted">({{ $tag->label }})</span>@endif
                                    <div class="small muted">{{ $tag->last_used_at ? 'Last used '.$studio->local($tag->last_used_at)->format('M j, g:i A') : 'Not used yet' }}</div></div>
                                <form method="post" action="{{ route('studio.admin.tags.revoke', ['accessCredential' => $tag]) }}" class="inline" data-confirm="Stop tag …{{ $tag->value_last4 }} working?">@csrf<button class="link link-danger small">Take back</button></form>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="small muted" style="margin-top:0">No tags yet. Members can also check in with the code in their account.</p>
                @endif
                @php $active = $attendees->where('status', 'active'); @endphp
                @if ($active->isNotEmpty())
                    <form method="post" action="{{ route('studio.admin.members.tags.store', ['member' => $member]) }}" class="form">
                        @csrf
                        <div class="grid-2">
                            <x-select name="attendee_id" label="For" :options="$active->pluck('name', 'id')->all()" />
                            <x-field name="label" label="Label (optional)" maxlength="60" placeholder="Blue fob" />
                        </div>
                        <x-field name="uid" label="Tag" required maxlength="100" autocomplete="off" placeholder="Scan the tag with the reader, or type its number" />
                        <button class="btn btn-secondary">Give tag</button>
                    </form>
                @endif
            </div>
        </section>

        <section>
            <h2>Upcoming</h2>
            @if ($upcoming->isEmpty())
                <div class="card empty">No upcoming bookings.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <tbody>
                        @foreach ($upcoming as $b)
                            <tr>
                                <td class="nowrap">{{ $studio->local($b->classSession->starts_at)->format('D M j · g:i A') }}</td>
                                <td><a href="{{ route('studio.admin.sessions.show', ['session' => $b->class_session_id]) }}">{{ $b->classSession->program->title }}</a></td>
                                <td>{{ $b->attendee->first_name }}</td>
                                <td>{{ $b->statusLabel() }}{{ $b->position ? ' #'.$b->position : '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($orders->isNotEmpty())
            <section>
                <h2>Purchases</h2>
                @include('admin.orders._table', ['orders' => $orders, 'showMember' => false])
            </section>
        @endif

        <section>
            <h2>Recent</h2>
            @if ($recent->isEmpty())
                <div class="card empty">No past classes.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <tbody>
                        @foreach ($recent as $b)
                            <tr>
                                <td class="nowrap">{{ $studio->local($b->classSession->starts_at)->format('M j, Y') }}</td>
                                <td>{{ $b->classSession->program->title }}</td>
                                <td>{{ $b->attendee->first_name }}</td>
                                <td>{{ $b->statusLabel() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <h2>Activity</h2>
            @if ($activity->isEmpty())
                <div class="card empty">Nothing recorded yet.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <tbody>
                        @foreach ($activity as $event)
                            <tr>
                                <td class="nowrap small muted">{{ $studio->local($event->occurred_at)->format('M j, g:i A') }}</td>
                                <td>
                                    {{ $event->label() }}@if ($event->attendee && ! $event->attendee->is_self) <span class="muted">· {{ $event->attendee->first_name }}</span>@endif
                                    @if ($class = $event->classLabel($studio))<div class="small muted">{{ $class }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <aside class="stack">
        <div class="card">
            <h3>Risk of leaving</h3>
            @if ($latest = $risk->first())
                <p style="margin:.2rem 0 .6rem"><span class="badge {{ $latest->badgeClass() }}">{{ $latest->levelLabel() }}</span> <span class="small muted">{{ $latest->score }} of 100 · {{ $latest->as_of->format('M j') }}</span></p>
                @if ($latest->concerns())
                    <ul class="small" style="margin:0 0 .5rem;padding-left:1.1rem">
                        @foreach ($latest->concerns() as $concern)<li>{{ $concern }}</li>@endforeach
                    </ul>
                @endif
                @foreach (array_filter($latest->reasons, fn ($r) => $r['points'] < 0) as $good)
                    <div class="small muted">✓ {{ $good['label'] }}</div>
                @endforeach
                @if ($risk->count() > 1)
                    <div class="small muted mt">Before: @foreach ($risk->skip(1) as $earlier){{ $earlier->as_of->format('M j') }} {{ strtolower($earlier->levelLabel()) }}@if (! $loop->last), @endif @endforeach</div>
                @endif
            @else
                <p class="small muted" style="margin:0">Scored each night from 2 AM, from their activity.</p>
            @endif
        </div>
        <div class="card">
            <h3>Plans</h3>
            @forelse ($entitlements as $e)
                <div class="item small" style="padding:.4rem 0;border-bottom:1px solid var(--line)">
                    <div>
                        <strong>{{ $e->plan?->name ?? 'Plan' }}</strong>
                        <div class="muted">
                            @if ($e->isCredits()){{ $e->credits_remaining }}/{{ $e->credits_total }} credits @endif
                            @if ($e->expires_at) · ends {{ $studio->local($e->expires_at)->format('M j, Y') }}@elseif ($e->current_period_end) · renews {{ $studio->local($e->current_period_end)->format('M j') }}@endif
                            @if ($e->payment)
                                · {{ $e->payment->isComp() ? 'comped' : 'paid in person ('.strtolower($e->payment->methodLabel()).')' }}
                            @elseif (! $e->stripe_subscription_id && ! $e->stripe_checkout_session_id)
                                · comped
                            @endif
                        </div>
                    </div>
                    <span @class(['badge', 'badge-full' => $e->status === 'past_due', 'badge-muted' => in_array($e->status, ['cancelled', 'expired'])])>{{ $e->statusLabel() }}</span>
                </div>
            @empty
                <p class="muted small">None.</p>
            @endforelse
        </div>
        <div class="card">
            <h3>Emails</h3>
            <p class="small" style="margin:0 0 .5rem">
                Marketing:
                @if ($membership->allowsMarketingEmail())
                    <strong>opted in</strong> <span class="muted">{{ $studio->local($membership->email_marketing_consent_at)->format('M j, Y') }}{{ $membership->email_marketing_consent_source ? ' ('.str_replace('_', ' ', $membership->email_marketing_consent_source).')' : '' }}</span>
                @elseif ($membership->email_unsubscribed_at)
                    <strong>unsubscribed</strong> <span class="muted">{{ $studio->local($membership->email_unsubscribed_at)->format('M j, Y') }}</span>
                @else
                    <strong>not opted in</strong>
                @endif
            </p>
            <p class="small muted" style="margin:0 0 .5rem">Members choose this themselves, on joining or in their profile.</p>
            @forelse ($deliveries as $d)
                <div class="item small" style="padding:.35rem 0;border-top:1px solid var(--line)">
                    <div>{{ $d->kindLabel() }} <span class="muted">· {{ $studio->local($d->created_at)->format('M j, g:i A') }}</span></div>
                    <span @class(['badge', 'badge-full' => $d->status === 'failed', 'badge-muted' => in_array($d->status, ['queued', 'suppressed'])])>{{ $d->statusLabel() }}</span>
                </div>
            @empty
                <p class="muted small" style="margin:0">No emails yet.</p>
            @endforelse
        </div>
        <div class="card">
            <h3>Add a plan</h3>
            <p class="muted small">Record a payment taken in person, or give a plan at no charge.</p>
            @if ($plans->isEmpty())
                <p class="muted small" style="margin:0"><a href="{{ route('studio.admin.plans.create') }}">Create a plan</a> first.</p>
            @else
                <form method="post" action="{{ route('studio.admin.members.grant', ['member' => $member]) }}" class="form">
                    @csrf
                    <x-select name="plan_id" label="Plan" :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.$p->priceLabel()])->all()" />
                    <x-select name="method" label="Payment" :options="\App\Models\Payment::METHODS" value="cash" />
                    <div class="form" data-show-when="method=cash,check,card,transfer,app,other">
                        <x-money name="amount" label="Amount" :currency="$studio->currency" hint="Leave blank for the plan’s price." />
                        <x-field name="paid_on" label="Received on" type="date" :value="$studio->localNow()->toDateString()" />
                        <x-field name="note" label="Note" placeholder="Check #1042" maxlength="255" />
                    </div>
                    <x-field name="ends_on" label="Valid until (optional)" type="date" hint="Leave blank and packs follow their validity, memberships paid in person run one month, and comps don’t end." />
                    <button class="btn btn-small">Add plan</button>
                </form>
            @endif
        </div>
    </aside>
</div>
@endsection
