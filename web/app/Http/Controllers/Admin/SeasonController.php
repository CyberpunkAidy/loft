<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\Studio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SeasonController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.seasons.index', [
            'seasons' => $studio->seasons()->withCount('programs')->orderByDesc('starts_on')->get(),
        ]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $this->save($studio, new Season(['studio_id' => $studio->id]), $this->validated($request));

        return back()->with('success', 'Season saved. Recurring programs in it can now generate sessions.');
    }

    public function edit(Studio $studio, Season $season): View
    {
        return view('admin.seasons.edit', ['season' => $season]);
    }

    public function update(Request $request, Studio $studio, Season $season): RedirectResponse
    {
        $this->save($studio, $season, $this->validated($request));

        return redirect()->route('studio.admin.seasons.index')
            ->with('success', 'Season saved. Open each program and save it to bring its sessions in step with new dates.');
    }

    public function destroy(Studio $studio, Season $season): RedirectResponse
    {
        $season->delete();

        return back()->with('success', 'Season deleted. Its sessions were kept.');
    }

    private function save(Studio $studio, Season $season, array $data): void
    {
        DB::transaction(function () use ($studio, $season, $data) {
            if ($data['is_current']) {
                $studio->seasons()->where('id', '!=', $season->id ?? 0)->update(['is_current' => false]);
            }
            $season->fill($data)->save();
        });
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $data['is_current'] = $request->boolean('is_current');

        return $data;
    }
}
