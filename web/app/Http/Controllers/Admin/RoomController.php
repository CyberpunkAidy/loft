<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Apparatus;
use App\Models\Room;
use App\Models\Studio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Rooms, their fire-code limits, and the apparatus rigged in each. */
class RoomController extends Controller
{
    /** Blank apparatus rows on the form. */
    private const NEW_ROWS = 3;

    public function index(Studio $studio): View
    {
        return view('admin.rooms.index', [
            'rooms' => $studio->rooms()->with('apparatus')
                ->withCount(['sessions as upcoming_count' => fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '>', now())])
                ->orderBy('name')->get(),
        ]);
    }

    public function create(Studio $studio): View
    {
        return view('admin.rooms.form', ['room' => new Room(['max_capacity' => 12]), 'newRows' => self::NEW_ROWS]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $room = $this->save($request, $studio, new Room(['studio_id' => $studio->id]));

        return redirect()->route('studio.admin.rooms.edit', ['room' => $room])
            ->with('success', 'Room saved. Choose it on a program to use its apparatus.');
    }

    public function edit(Studio $studio, Room $room): View
    {
        return view('admin.rooms.form', ['room' => $room->load('apparatus'), 'newRows' => self::NEW_ROWS]);
    }

    public function update(Request $request, Studio $studio, Room $room): RedirectResponse
    {
        $this->save($request, $studio, $room);

        return back()->with('success', 'Room saved.');
    }

    public function destroy(Studio $studio, Room $room): RedirectResponse
    {
        $room->delete();

        return redirect()->route('studio.admin.rooms.index')
            ->with('success', "{$room->name} deleted. Its classes no longer have a room or apparatus limits.");
    }

    private function save(Request $request, Studio $studio, Room $room): Room
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail) use ($studio, $room) {
                if ($studio->rooms()->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])->whereKeyNot($room->id ?? 0)->exists()) {
                    $fail('You already have a room with that name.');
                }
            }],
            'max_capacity' => ['required', 'integer', 'between:1,1000'],
            'apparatus' => ['array'],
            'apparatus.*.name' => ['required', 'string', 'max:60'],
            'apparatus.*.quantity' => ['required', 'integer', 'between:1,200'],
            'apparatus.*.max_per_item' => ['required', 'integer', 'between:1,20'],
            'apparatus.*.remove' => ['nullable', 'boolean'],
            'new_apparatus' => ['array'],
            'new_apparatus.*.name' => ['nullable', 'string', 'max:60'],
            'new_apparatus.*.quantity' => ['nullable', 'required_with:new_apparatus.*.name', 'integer', 'between:1,200'],
            'new_apparatus.*.max_per_item' => ['nullable', 'required_with:new_apparatus.*.name', 'integer', 'between:1,20'],
        ], [
            'new_apparatus.*.quantity.required_with' => 'How many are rigged?',
            'new_apparatus.*.max_per_item.required_with' => 'How many people can use each?',
        ], [
            'max_capacity' => 'capacity',
            'apparatus.*.name' => 'name',
            'apparatus.*.quantity' => 'quantity',
            'apparatus.*.max_per_item' => 'people on each',
            'new_apparatus.*.quantity' => 'quantity',
            'new_apparatus.*.max_per_item' => 'people on each',
        ]);

        $existing = $room->exists ? $room->apparatus()->get()->keyBy('id') : collect();
        $kept = collect($data['apparatus'] ?? [])->filter(fn ($row, $id) => $existing->has($id) && empty($row['remove']));
        $added = collect($data['new_apparatus'] ?? [])->filter(fn ($row) => filled($row['name'] ?? null));
        $names = $kept->pluck('name')->merge($added->pluck('name'))->map(fn ($n) => mb_strtolower(trim($n)));
        if ($names->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['apparatus' => 'Give each apparatus in the room its own name.']);
        }

        return DB::transaction(function () use ($room, $studio, $data, $existing, $kept, $added) {
            $room->fill(['name' => $data['name'], 'max_capacity' => $data['max_capacity']])->save();

            foreach ($existing as $id => $apparatus) {
                if (! $kept->has($id)) {
                    $apparatus->delete(); // bookings on it keep their spot, without apparatus

                    continue;
                }
                $row = $kept[$id];
                $apparatus->update(['name' => trim($row['name']), 'quantity' => (int) $row['quantity'], 'max_per_item' => (int) $row['max_per_item']]);
                $this->clampAllocations($apparatus);
            }
            foreach ($added as $row) {
                $room->apparatus()->create([
                    'studio_id' => $studio->id,
                    'name' => trim($row['name']),
                    'quantity' => (int) $row['quantity'],
                    'max_per_item' => (int) $row['max_per_item'],
                ]);
            }

            return $room;
        });
    }

    /** Fewer rigged, or a lower limit per item: classes can't use more than there is. */
    private function clampAllocations(Apparatus $apparatus): void
    {
        foreach (['program_apparatus', 'session_apparatus'] as $table) {
            DB::table($table)->where('apparatus_id', $apparatus->id)->where('quantity', '>', $apparatus->quantity)->update(['quantity' => $apparatus->quantity]);
            DB::table($table)->where('apparatus_id', $apparatus->id)->where('max_per_item', '>', $apparatus->max_per_item)->update(['max_per_item' => $apparatus->max_per_item]);
        }
    }
}
