<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Category;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Bookings;
use App\Services\Rooms;
use App\Services\SessionGenerator;
use App\Support\ApparatusAllocation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Classes and events (the plugin's loft_program post type and its meta box). */
class ProgramController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.programs.index', [
            'programs' => $studio->programs()->with(['season', 'categories', 'instructor'])
                ->withCount(['sessions as upcoming_count' => fn ($q) => $q->where('starts_at', '>', now())->where('status', 'scheduled')])
                ->orderByDesc('is_published')->orderBy('weekday')->orderBy('start_time')->orderBy('title')
                ->get(),
        ]);
    }

    public function create(Studio $studio): View
    {
        $program = new Program([
            'type' => 'class',
            'capacity' => 10,
            'duration_min' => 60,
            'start_time' => '17:00',
            'season_id' => $studio->currentSeason()?->id,
            'is_published' => true,
        ]);

        return view('admin.programs.form', $this->formData($studio, $program));
    }

    public function store(Request $request, Studio $studio, SessionGenerator $generator, Rooms $rooms): RedirectResponse
    {
        [$data, $allocation] = $this->validated($request, $studio);
        $program = $studio->programs()->create($data);
        $program->apparatus()->sync($allocation);
        $this->syncCategories($request, $studio, $program);
        $result = $generator->sync($program->fresh());

        return redirect()->route('studio.admin.programs.edit', ['program' => $program])
            ->with('success', "Saved. {$result['created']} sessions created.")
            ->with('warning', $this->clashWarning($rooms, $program));
    }

    public function edit(Studio $studio, Program $program): View
    {
        $program->load('categories');

        return view('admin.programs.form', $this->formData($studio, $program) + [
            'sessions' => $program->sessions()->withSeatCounts()->with('room')->where('starts_at', '>', now()->subDay())->orderBy('starts_at')->limit(40)->get(),
            'sessionTotal' => $program->sessions()->count(),
        ]);
    }

    public function update(Request $request, Studio $studio, Program $program, SessionGenerator $generator, Bookings $bookings, Rooms $rooms): RedirectResponse
    {
        [$data, $allocation] = $this->validated($request, $studio);
        if ($request->boolean('apply_upcoming') && $data['delivery'] !== 'hybrid') {
            $stranded = $program->sessions()->where('status', 'scheduled')->where('starts_at', '>', now())
                ->whereHas('bookings', fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES)->where('online_spot', true))->count();
            if ($stranded) {
                throw ValidationException::withMessages(['delivery' => ($stranded === 1 ? '1 upcoming class has' : "{$stranded} upcoming classes have").' people booked online. Keep it in the studio and online, or don’t apply the change to upcoming sessions.']);
            }
        }
        $program->update($data);
        $program->apparatus()->sync($allocation);
        $this->syncCategories($request, $studio, $program);
        $result = $generator->sync($program->fresh());
        $lead = 'Saved.';
        if ($request->boolean('apply_upcoming')) {
            $updated = $generator->applyToUpcoming($program->fresh(), $bookings);
            $lead = "Saved, and {$updated} upcoming ".($updated === 1 ? 'session' : 'sessions').' updated.';
        }

        return back()->with('success', $this->syncMessage($lead, $result))
            ->with('warning', $this->clashWarning($rooms, $program));
    }

    private function clashWarning(Rooms $rooms, Program $program): ?string
    {
        $count = $rooms->clashes($program->fresh());

        return $count ? "{$count} upcoming ".($count === 1 ? 'session shares' : 'sessions share')." {$program->room->name} with another class at the same time. Open a session to see which." : null;
    }

    public function sync(Studio $studio, Program $program, SessionGenerator $generator): RedirectResponse
    {
        return back()->with('success', $this->syncMessage('Sessions are in step with the schedule.', $generator->sync($program)));
    }

    public function destroy(Studio $studio, Program $program): RedirectResponse
    {
        if ($program->sessions()->whereHas('bookings')->exists()) {
            $program->update(['is_published' => false]);

            return redirect()->route('studio.admin.programs.index')
                ->with('success', "“{$program->title}” has bookings, so it was hidden from the schedule instead of deleted.");
        }
        $program->delete();

        return redirect()->route('studio.admin.programs.index')->with('success', 'Program deleted.');
    }

    private function syncMessage(string $lead, array $result): string
    {
        $parts = [];
        if ($result['created']) {
            $parts[] = "{$result['created']} sessions created";
        }
        if ($result['removed']) {
            $parts[] = "{$result['removed']} unbooked sessions no longer on the schedule removed";
        }

        return $lead.($parts ? ' '.ucfirst(implode('; ', $parts)).'.' : '');
    }

    private function formData(Studio $studio, Program $program): array
    {
        return [
            'program' => $program,
            'seasons' => $studio->seasons()->orderByDesc('starts_on')->get(),
            'instructors' => $studio->members()->wherePivotIn('role', StudioUser::STAFF_ROLES)->orderBy('first_name')->get(),
            'categories' => $studio->categories()->orderBy('name')->get()->groupBy('type'),
            'selected' => $program->exists ? $program->categories->pluck('id')->all() : [],
            'rooms' => $studio->rooms()->with('apparatus')->orderBy('name')->get(),
            'allocation' => $program->exists
                ? $program->apparatus()->get()->mapWithKeys(fn ($a) => [$a->id => ['quantity' => $a->pivot->quantity, 'max_per_item' => $a->pivot->max_per_item]])->all()
                : [],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array{quantity: int, max_per_item: int}>} the program's fields and its apparatus */
    private function validated(Request $request, Studio $studio): array
    {
        $staffIds = $studio->studioUsers()->whereIn('role', StudioUser::STAFF_ROLES)->pluck('user_id')->all();
        $room = $request->filled('room_id') ? $studio->rooms()->with('apparatus')->find($request->integer('room_id')) : null;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', 'in:class,event'],
            'season_id' => ['nullable', Rule::exists('seasons', 'id')->where('studio_id', $studio->id)],
            'weekday' => ['nullable', 'required_if:type,class', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_min' => ['required', 'integer', 'between:15,600'],
            'event_date' => ['nullable', 'required_if:type,event', 'date'],
            'capacity' => ['required', 'integer', 'between:1,500', ApparatusAllocation::capacityRule($room)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('studio_id', $studio->id)],
            'waitlist_capacity' => ['nullable', 'integer', 'between:0,500'],
            'delivery' => ['nullable', Rule::in(array_keys(ClassSession::DELIVERIES))],
            'online_capacity' => ['nullable', 'integer', 'between:1,1000'],
            'meeting_url' => ['nullable', 'url:https,http', 'max:500'],
            'dropin_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'instructor_id' => ['nullable', Rule::in($staffIds)],
            'min_age' => ['nullable', 'integer', 'between:0,120'],
            'max_age' => ['nullable', 'integer', 'between:0,120', 'gte:min_age'],
            'members_only' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
        ], ['weekday.required_if' => 'Pick the weekday this class meets.', 'event_date.required_if' => 'Pick the event date.'], ['waitlist_capacity' => 'waitlist limit', 'online_capacity' => 'online spots']);
        $data['delivery'] ??= 'in_person';
        $data['online_capacity'] = $data['delivery'] === 'hybrid' ? ($data['online_capacity'] ?? null) : null;
        $allocation = ApparatusAllocation::fromRequest($request, $room);

        $data['dropin_price_cents'] = Money::toCents($data['dropin_price'] ?? null);
        unset($data['dropin_price']);
        $data['members_only'] = $request->boolean('members_only');
        $data['is_published'] = $request->boolean('is_published');
        if ($data['type'] === 'event') {
            $data['weekday'] = null;
        } else {
            $data['event_date'] = null;
        }

        return [$data, $allocation];
    }

    /** Ticked categories plus any typed as "new" (comma-separated), per type. */
    private function syncCategories(Request $request, Studio $studio, Program $program): void
    {
        $validIds = $studio->categories()->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', (array) $request->input('categories', [])), $validIds));
        foreach (array_keys(Category::TYPES) as $type) {
            $new = trim((string) $request->input("new_categories.{$type}", ''));
            if ($new !== '') {
                $ids = array_merge($ids, Category::ensure($studio, $type, explode(',', $new)));
            }
        }
        $program->categories()->sync(array_unique($ids));
    }
}
