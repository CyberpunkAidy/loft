<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\StudioLogos;
use App\Support\LogoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Settings → Logo: upload or remove the studio's logo (decision 41). StudioLogos checks and redraws it. */
class LogoController extends Controller
{
    public function store(Request $request, Studio $studio, StudioLogos $logos): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:'.intdiv(StudioLogos::MAX_BYTES, 1024)]], [
            'logo.required' => 'Choose an image to upload.',
            'logo.max' => 'That file is over 2 MB. Try a smaller version of your logo.',
            'logo.uploaded' => 'That file didn’t upload. Try a smaller version of your logo.',
        ]);
        try {
            $logos->store($studio, (string) $request->file('logo')->get());
        } catch (LogoException $e) {
            throw ValidationException::withMessages(['logo' => $e->getMessage()]);
        }

        return back()->with('success', 'Logo saved. It now shows on your studio’s pages and emails.');
    }

    public function destroy(Studio $studio, StudioLogos $logos): RedirectResponse
    {
        $logos->remove($studio);

        return back()->with('success', 'Logo removed.');
    }
}
