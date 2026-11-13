@extends('layouts.admin')

@section('title', 'Text messages')

@php
    use App\Services\Texts;
    $sent = (int) ($month['sent'] ?? 0) + (int) ($month['delivered'] ?? 0);
    $help = $studio->name.': For help, '.($studio->contact_email ? 'email '.$studio->contact_email : 'contact the studio').'. Msg & data rates may apply. Reply STOP to opt out.';
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Text messages</h1>
        <p>Text members from your own number: class reminders, waitlist spots and cancelled classes, and your <a href="{{ route('studio.admin.automations.index') }}">automations</a> if you choose. Texts go only to members who opt in, and anyone can reply STOP. You pay Twilio for your texts and number; Loft adds nothing.</p>
    </div>
</div>
@if ($twilioError)<div class="alert alert-error">Twilio: {{ $twilioError }}</div>@endif

<div class="card narrow">
    @if ($studio->isDemo())
        <h2>Texts are off in the demo</h2>
        <p class="muted" style="margin:0">No texts go out from a demo studio. In your own studio, you connect your Twilio number here, and members who opt in get class reminders and booking updates by text.</p>
    @elseif ($studio->textsReady())
        <h2>Texts are on <span class="badge">Live</span></h2>
        <p>Texts come from <strong>{{ $studio->textsFrom() }}</strong>, on Twilio account <code>{{ substr($studio->twilio_account_sid, 0, 6).'…'.substr($studio->twilio_account_sid, -4) }}</code>. Replies come to Loft: STOP and START change what members get, and anything else is emailed to your owners and admins.</p>
        <div class="actions">
            <a class="btn btn-secondary" href="https://console.twilio.com/" target="_blank" rel="noopener">Open Twilio console ↗</a>
            <form method="post" action="{{ route('studio.admin.texts.destroy') }}" class="inline" data-confirm="Stop sending texts? Members’ choices are kept in case you connect again.">
                @csrf @method('delete')
                <button class="btn btn-secondary">Disconnect</button>
            </form>
        </div>
    @elseif ($studio->twilio_account_sid)
        <h2>Choose the number to text from</h2>
        @if ($numbers === [])
            <p>Your Twilio account has no number that can send texts yet. In Twilio, go to <strong>Phone Numbers → Buy a number</strong> and buy a local number with SMS, then reload this page.</p>
        @elseif ($numbers)
            <form method="post" action="{{ route('studio.admin.texts.number') }}" class="form">
                @csrf
                <div class="list" role="radiogroup" aria-label="Number">
                    @foreach ($numbers as $number)
                        <label class="pick">
                            <input type="radio" name="number_sid" value="{{ $number['sid'] }}" @checked(old('number_sid') === $number['sid'] || count($numbers) === 1)>
                            <span class="grow">
                                <strong>{{ \App\Support\Phone::display($number['phone_number']) }}</strong>
                                @if ($number['friendly_name'] !== $number['phone_number'])<span class="muted small"> · {{ $number['friendly_name'] }}</span>@endif
                                @if ($number['sms_url'] && ! str_contains($number['sms_url'], '/twilio/'))
                                    <span class="muted small" style="display:block">Texts to it go to {{ parse_url($number['sms_url'], PHP_URL_HOST) ?: $number['sms_url'] }} now. Loft takes them over.</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('number_sid')<p class="small" style="color:var(--err)">{{ $message }}</p>@enderror
                <p class="small muted" style="margin:0">Loft sets the number to send texts it receives (replies, STOP and START) to Loft.</p>
                <div class="actions"><button class="btn">Text from this number</button></div>
            </form>
        @endif
        <form method="post" action="{{ route('studio.admin.texts.destroy') }}" class="inline">
            @csrf @method('delete')
            <button class="btn btn-secondary btn-small">Use a different Twilio account</button>
        </form>
    @else
        <h2>Connect your Twilio number</h2>
        <ol class="steps-list">
            <li><strong>Make a Twilio account</strong> at <a href="https://www.twilio.com/" target="_blank" rel="noopener">twilio.com</a>, and add a payment method. A trial account can only text numbers you’ve verified.</li>
            <li><strong>Buy a local number</strong> that can send texts (<strong>Phone Numbers → Buy a number</strong>).</li>
            <li><strong>Register it for US texting (A2P 10DLC)</strong> under <strong>Messaging → Regulatory compliance</strong>: your business as a brand, then a campaign. Carriers block most texts from numbers that aren’t registered, and approval can take a few days to a few weeks, so start early. What to fill in is <a href="#registration">below</a>.</li>
            <li><strong>Paste your Account SID and Auth Token</strong> from the Twilio console’s home page.</li>
        </ol>
        <form method="post" action="{{ route('studio.admin.texts.connect') }}" class="form">
            @csrf
            <x-field name="account_sid" label="Account SID" placeholder="AC…" autocomplete="off" spellcheck="false" required />
            <x-field name="auth_token" label="Auth Token" type="password" autocomplete="off" required hint="Kept encrypted. Loft uses it only to send your texts and to check that messages from Twilio are real." />
            <div class="actions"><button class="btn">Connect Twilio</button></div>
        </form>
    @endif
</div>

@if ($studio->textsReady())
    <section class="mt">
        <h2>This month</h2>
        <div class="stats">
            <div class="stat"><strong>{{ $sent }}</strong><span>{{ $sent === 1 ? 'Text' : 'Texts' }} sent</span></div>
            <div class="stat"><strong>{{ (int) ($month['delivered'] ?? 0) }}</strong><span>Delivered</span></div>
            <div class="stat"><strong>{{ (int) ($month['failed'] ?? 0) }}</strong><span>Not delivered</span></div>
            <div class="stat"><strong>{{ $optedIn['updates'] }}</strong><span>Get booking texts (of {{ $optedIn['members'] }} members)</span></div>
            <div class="stat"><strong>{{ $optedIn['marketing'] }}</strong><span>Get news and offers by text</span></div>
        </div>
        @if ($optedIn['stopped'])<p class="small muted" style="margin-top:-1rem">{{ $optedIn['stopped'] }} {{ $optedIn['stopped'] === 1 ? 'member has' : 'members have' }} replied STOP.</p>@endif
    </section>

    <div class="split mt">
        <form method="post" action="{{ route('studio.admin.texts.settings') }}" class="card form">
            @csrf @method('put')
            <h3 style="margin:0">What goes by text</h3>
            <p class="small muted" style="margin:0">To members who opted in to booking texts, as well as the email. <a href="{{ route('studio.admin.automations.index') }}">Automations</a> each choose email, text or both.</p>
            @foreach (['text_reminders' => 'Class reminders, '.$studio->setting('reminder_hours_before').' hours before (set in Settings)', 'text_waitlist' => 'A spot opened on the waitlist', 'text_cancellations' => 'A class you cancelled'] as $key => $label)
                <x-checkbox :name="$key" :label="$label" :checked="(bool) $studio->setting($key)" />
                <div class="text-bubble small" style="margin:-.3rem 0 .4rem 1.7rem">{{ $samples[$key] }}</div>
            @endforeach
            <div class="actions"><button class="btn">Save</button></div>
        </form>

        <div class="card">
            <h3>Send a test</h3>
            <form method="post" action="{{ route('studio.admin.texts.test') }}" class="form">
                @csrf
                <x-field name="test_phone" label="Mobile number" type="tel" :value="auth()->user()->phone" required />
                <div class="actions"><button class="btn btn-secondary">Send test text</button></div>
            </form>
            <h3 class="mt">Problems</h3>
            @forelse ($problems as $problem)
                <div class="item small" style="padding:.4rem 0;border-top:1px solid var(--line)">
                    <div>
                        <strong>{{ $problem->user?->name ?? ($problem->kind === 'text_test' ? 'Test' : 'Someone') }}</strong> · {{ $problem->kindLabel() }} <span class="muted">· {{ $studio->local($problem->created_at)->format('M j, g:i A') }}</span>
                        <div class="muted">{{ $problem->error }}</div>
                    </div>
                </div>
            @empty
                <p class="small muted" style="margin:0">None. Texts that don’t arrive show here, with why.</p>
            @endforelse
        </div>
    </div>
@endif

@unless ($studio->isDemo())
    <details class="card mt registration" id="registration" @unless ($studio->textsReady()) open @endunless>
        <summary><strong>What to put in your A2P 10DLC registration</strong></summary>
        <p class="small muted">Twilio asks how members agree to texts, and for examples. Copy these into your campaign, changing anything that doesn’t fit.</p>
        <dl class="copy-list">
            <dt>Use case</dt>
            <dd>Mixed, if you’ll text news and offers as well as booking updates. Otherwise Account Notification.</dd>
            <dt>Campaign description</dt>
            <dd><pre>{{ $studio->name }} texts its members about their classes: reminders, spots that open on a waitlist, and classes the studio cancels. Members who agree separately also get occasional news and offers.</pre></dd>
            <dt>How members opt in</dt>
            <dd><pre>Members opt in on the {{ $studio->name }} website or app (powered by Loft): an unticked box when they create an account, or in My account → Profile, next to their mobile number ({{ route('studio.account.profile', $studio) }}). Booking texts and news-and-offers texts are separate boxes, and neither is required. Next to them: "{{ Texts::DISCLOSURE }}" with a link to the privacy policy ({{ route('privacy') }}). Mobile numbers aren't shared with third parties for marketing.</pre></dd>
            <dt>Opt-in confirmation (sent by Loft)</dt>
            <dd><pre>{{ Texts::plain($studio->name.': You’ll now get texts about your classes (reminders, waitlist spots, cancellations). Msg frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out.') }}</pre></dd>
            <dt>Help message</dt>
            <dd><pre>{{ $help }}</pre><span class="small muted">In Twilio, set this as your number’s HELP reply, under the Messaging Service’s Opt-Out Management.</span></dd>
            <dt>Keywords</dt>
            <dd>Opt-out: STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT. Opt-in: START. Help: HELP, INFO.</dd>
            <dt>Sample messages</dt>
            <dd>
                @foreach ($samples as $sample)<pre>{{ $sample }}</pre>@endforeach
            </dd>
            <dt>Other answers</dt>
            <dd>Messages include links: yes. Phone numbers: no. Age-gated content: no. Direct lending: no.</dd>
        </dl>
    </details>
@endunless
@endsection
