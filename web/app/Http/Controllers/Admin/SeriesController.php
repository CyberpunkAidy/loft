<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Series;
use App\Models\SeriesEnrollment;
use App\Models\Studio;
use App\Services\SeriesSignups;
use App\Support\BookingException;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Series (decision 47): runs of a program's classes sold as one. Staff set them up, see who's
 * signed up, add someone (paid at the desk, or free) and take someone out.
 */
class SeriesController extends Controller
{
    public function index(Studio $studio, SeriesSignups $signups): View
    {
        $series = $studio->series()->with('program')
            ->withCount(['enrollments as signed_up' => fn ($q) => $q->where('status', 'active'), 'sessions as class_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->where('ends_on', '>=', $studio->localNow()->subDays(30)->toDateString())->orderBy('starts_on')->get();

        return view('admin.series.index', ['series' => $series, 'quotes' => $series->mapWithKeys(fn (Series $s) => [$s->id => $signups->quote($s)])]);
    }

    public function create(Request $request, Studio $studio): View
    {
        $program = $request->filled('program') ? $studio->programs()->find($request->integer('program')) : null;

        return view('admin.series.form', ['series' => new Series(['program_id' => $program?->id, 'name' => $program?->title]), 'programs' => $this->programs($studio)]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $series = $studio->series()->create($this->validated($request, $studio));
        $classes = $series->attachSessions();

        return redirect()->route('studio.admin.series.show', ['series' => $series])->with($classes ? 'success' : 'warning', $classes
            ? "Series created with {$classes} ".($classes === 1 ? 'class' : 'classes').'.'
            : 'Series created, but its program has no classes on those dates yet. Check the program’s day and season.');
    }

    public function show(Request $request, Studio $studio, Series $series, SeriesSignups $signups): View
    {
        $series->load('program');
        $enrollments = $series->enrollments()->with(['attendee', 'user'])->whereIn('status', ['active', 'pending', 'withdrawn'])
            ->orderByRaw("case status when 'active' then 1 when 'pending' then 2 else 3 end")->orderBy('enrolled_at')->orderBy('id')->get();
        $q = trim((string) $request->query('q', ''));
        $candidates = $q === '' ? collect() : $studio->attendees()->with('user')->where('status', 'active')
            ->tap(fn ($query) => Search::apply($query, ['first_name', 'last_name'], $q))
            ->whereNotIn('id', $enrollments->whereIn('status', ['active', 'pending'])->pluck('attendee_id'))
            ->orderBy('first_name')->orderBy('last_name')->limit(50)->get();

        return view('admin.series.show', [
            'series' => $series,
            'quote' => $signups->quote($series),
            'classes' => $series->sessions()->withSeatCounts()->get(),
            'enrollments' => $enrollments,
            'candidates' => $candidates,
            'q' => $q,
            'methods' => Payment::METHODS,
        ]);
    }

    public function edit(Studio $studio, Series $series): View
    {
        return view('admin.series.form', ['series' => $series, 'programs' => $this->programs($studio)]);
    }

    public function update(Request $request, Studio $studio, Series $series): RedirectResponse
    {
        $series->update($this->validated($request, $studio, $series));
        $classes = $series->attachSessions();

        return redirect()->route('studio.admin.series.show', ['series' => $series])->with('success', "Saved. The series has {$classes} ".($classes === 1 ? 'class' : 'classes').'.');
    }

    /** Add someone: paid at the desk, or free. Their classes are booked and they're emailed. */
    public function add(Request $request, Studio $studio, Series $series, SeriesSignups $signups): RedirectResponse
    {
        $data = $request->validate([
            'attendee_id' => ['required', 'integer'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        $attendee = $studio->attendees()->where('status', 'active')->findOrFail($data['attendee_id']);
        $amount = $data['method'] === 'comp' ? 0 : (int) round(100 * (float) ($data['amount'] ?? $signups->quote($series)['price_cents'] / 100));
        try {
            $enrollment = $signups->addByStaff($series, $attendee, $request->user(), $data['method'], $amount, $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('studio.admin.series.show', ['series' => $series])
            ->with('success', "{$attendee->first_name} is signed up and booked into {$enrollment->classes} ".($enrollment->classes === 1 ? 'class' : 'classes').'.');
    }

    /** Take someone out: their classes still to come are cancelled. Money back is a refund under Sales. */
    public function withdraw(Studio $studio, Series $series, SeriesEnrollment $enrollment, SeriesSignups $signups): RedirectResponse
    {
        $cancelled = $signups->withdraw($enrollment, 'staff');

        return back()->with('success', "{$enrollment->attendee->first_name} is out of the series, and {$cancelled} ".($cancelled === 1 ? 'class was' : 'classes were')
            .' cancelled. To give money back, refund their payment from the member’s page or Sales.');
    }

    /** @return Collection<int, string> */
    private function programs(Studio $studio): Collection
    {
        return $studio->programs()->where('type', 'class')->orderBy('title')->pluck('title', 'id');
    }

    private function validated(Request $request, Studio $studio, ?Series $series = null): array
    {
        $data = $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('studio_id', $studio->id)],
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'join_until_class' => ['required', 'integer', 'between:1,200'],
        ], ['ends_on.after_or_equal' => 'The last day can’t be before the first.']);
        // A class can only be in one series.
        $overlap = $studio->series()->where('program_id', $data['program_id'])->when($series, fn ($q) => $q->whereKeyNot($series->id))
            ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->first();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => "These dates overlap {$overlap->name} ({$overlap->datesLabel()}). A class can only be in one series."]);
        }

        return [
            'program_id' => (int) $data['program_id'],
            'name' => $data['name'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'price_cents' => (int) round(100 * (float) $data['price']),
            'join_until_class' => (int) $data['join_until_class'],
            'makeups' => $request->boolean('makeups'),
            'allow_dropins' => $request->boolean('allow_dropins'),
            'signups_open' => $request->boolean('signups_open'),
        ];
    }
}
