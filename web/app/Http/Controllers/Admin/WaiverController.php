<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\Waiver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Waiver text and versions (the plugin's loft_waiver post type). */
class WaiverController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('admin.waivers.index', [
            'waivers' => $studio->waivers()->withCount('signatures')->orderByDesc('is_active')->latest()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.waivers.form', ['waiver' => new Waiver(['version' => 1, 'is_active' => true])]);
    }

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        $data = $this->validated($request);
        $waiver = $studio->waivers()->create(['title' => $data['title'], 'body' => $data['body'], 'version' => 1]);
        if ($request->boolean('is_active')) {
            $this->activateWaiver($studio, $waiver);
        }

        return redirect()->route('studio.admin.waivers.index')->with('success', 'Waiver saved.');
    }

    public function edit(Studio $studio, Waiver $waiver): View
    {
        return view('admin.waivers.form', ['waiver' => $waiver]);
    }

    public function update(Request $request, Studio $studio, Waiver $waiver): RedirectResponse
    {
        $data = $this->validated($request);
        $bump = $request->boolean('new_version');
        $waiver->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'version' => $waiver->version + ($bump ? 1 : 0),
        ]);
        if ($request->boolean('is_active')) {
            $this->activateWaiver($studio, $waiver);
        } elseif ($waiver->is_active) {
            $waiver->update(['is_active' => false]);
        }

        return redirect()->route('studio.admin.waivers.index')->with('success', $bump
            ? "Published version {$waiver->version}. Everyone signs again before their next booking."
            : 'Waiver saved.');
    }

    public function activate(Studio $studio, Waiver $waiver): RedirectResponse
    {
        $this->activateWaiver($studio, $waiver);

        return back()->with('success', "“{$waiver->title}” is now the active waiver.");
    }

    private function activateWaiver(Studio $studio, Waiver $waiver): void
    {
        DB::transaction(function () use ($studio, $waiver) {
            $studio->waivers()->where('id', '!=', $waiver->id)->update(['is_active' => false]);
            $waiver->update(['is_active' => true]);
        });
        $studio->forgetActiveWaiver();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:50000'],
        ]);
    }
}
