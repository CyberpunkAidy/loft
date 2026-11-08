{{-- Payments staff recorded by hand. $showMember adds the member column (the studio-wide list). --}}
<div class="card card-flush table-wrap">
    <table class="table">
        <thead><tr><th>Date</th>@if ($showMember)<th>Member</th>@endif<th>Plan</th><th>Payment</th><th class="right">Amount</th><th>Recorded by</th><th></th></tr></thead>
        <tbody>
        @foreach ($payments as $p)
            <tr @class(['is-cancelled' => $p->isVoided()])>
                <td class="nowrap">{{ $p->paid_on->format('M j, Y') }}</td>
                @if ($showMember)
                    <td>@if ($p->user)<a href="{{ route('studio.admin.members.show', ['member' => $p->user_id]) }}">{{ $p->user->name }}</a>@else<span class="muted">Deleted account</span>@endif</td>
                @endif
                <td>{{ $p->plan?->name ?? '—' }}@if ($p->note)<div class="muted small">{{ $p->note }}</div>@endif</td>
                <td>{{ $p->methodLabel() }}@if ($p->isVoided()) <span class="badge badge-muted">Void</span>@endif</td>
                <td class="right nowrap">{{ $p->isComp() ? '—' : $p->amountLabel() }}</td>
                <td class="small muted">{{ $p->recordedBy?->name ?? '—' }}</td>
                <td class="right">
                    @unless ($p->isVoided())
                        <form method="post" action="{{ route('studio.admin.payments.void', ['payment' => $p]) }}" class="inline" data-confirm="Void this {{ $p->isComp() ? 'comp' : 'payment' }}? The plan it added is cancelled.">
                            @csrf
                            <button class="link link-danger">Void</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
