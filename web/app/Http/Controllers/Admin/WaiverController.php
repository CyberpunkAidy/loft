<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\Waiver;
use App\Services\WaiverDownload;
use App\Services\Waivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Waivers: their text and versions, which classes need them, how long a signature lasts, and a
 * download of every signature. Several can be in force at once.
 */
class WaiverController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.waivers.index', [
            'waivers' => $studio->waivers()->with('programs:id,title')->withCount('signatures')->orderByDesc('is_active')->latest()->get(),
        ]);
    }

    public function create(Studio $studio): View
    {
        return view('admin.waivers.form', ['waiver' => new Waiver(['version' => 1, 'is_active' => true]), 'programs' => $this->programs($studio)]);
    }

    public function store(Request $request, Studio $studio, Waivers $waivers): RedirectResponse
    {
        $data = $this->validated($request, $studio);
        $waiver = $studio->waivers()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'version' => 1,
            'scope' => $data['scope'],
            'validity_days' => $data['validity_days'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);
        $waiver->programs()->sync($data['scope'] === 'program' ? ($data['program_ids'] ?? []) : []);
        $waivers->currentVersion($waiver); // keep version 1's text
        $studio->forgetActiveWaivers();

        return redirect()->route('studio.admin.waivers.index')->with('success', 'Waiver saved.');
    }

    public function edit(Studio $studio, Waiver $waiver): View
    {
        return view('admin.waivers.form', ['waiver' => $waiver->load('programs:id'), 'programs' => $this->programs($studio)]);
    }

    public function update(Request $request, Studio $studio, Waiver $waiver, Waivers $waivers): RedirectResponse
    {
        $data = $this->validated($request, $studio);
        $published = $waivers->saveText($waiver, $data['title'], $data['body'], $request->boolean('new_version'));
        $waiver->update([
            'scope' => $data['scope'],
            'validity_days' => $data['validity_days'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);
        $waiver->programs()->sync($data['scope'] === 'program' ? ($data['program_ids'] ?? []) : []);
        $studio->forgetActiveWaivers();

        return redirect()->route('studio.admin.waivers.index')->with('success', $published
            ? "Published version {$waiver->version}. Everyone signs again before their next class."
            : 'Waiver saved.');
    }

    /** Turn a waiver on or off. */
    public function activate(Studio $studio, Waiver $waiver): RedirectResponse
    {
        $waiver->update(['is_active' => ! $waiver->is_active]);
        $studio->forgetActiveWaivers();

        return back()->with('success', $waiver->is_active ? "“{$waiver->title}” is now in force." : "“{$waiver->title}” is turned off.");
    }

    /** Every signature, the text of every version, and the drawn signatures, as a ZIP. */
    public function download(Studio $studio, WaiverDownload $download): BinaryFileResponse
    {
        $name = Str::slug($studio->name).'-signed-waivers-'.$studio->localNow()->format('Y-m-d').'.zip';

        return response()->download($download->build($studio), $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function programs(Studio $studio)
    {
        return $studio->programs()->orderBy('title')->get(['id', 'title']);
    }

    private function validated(Request $request, Studio $studio): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:50000'],
            'scope' => ['nullable', Rule::in(array_keys(Waiver::SCOPES))],
            'program_ids' => ['nullable', 'array', 'required_if:scope,program'],
            'program_ids.*' => ['integer', Rule::exists('programs', 'id')->where('studio_id', $studio->id)],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], ['program_ids.required_if' => 'Choose at least one class this waiver applies to.']) + ['scope' => 'studio'];
    }
}
