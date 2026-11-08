<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Consent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The unsubscribe link in marketing emails: a signed URL, so it works without signing in. Mail apps
 * that support one-click unsubscribe (RFC 8058) POST to it directly.
 */
class UnsubscribeController extends Controller
{
    public function show(Studio $studio, User $member): View
    {
        return view('studio.unsubscribe', ['member' => $member, 'subscribed' => $this->membership($studio, $member)->allowsMarketingEmail()]);
    }

    public function store(Request $request, Studio $studio, User $member, Consent $consent): RedirectResponse|Response
    {
        $consent->setMarketingEmail($this->membership($studio, $member), false, 'unsubscribe_link');
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('Unsubscribed.', 200);
        }

        return redirect()->to($request->fullUrl())->with('success', "You won’t get marketing emails from {$studio->name} any more.");
    }

    private function membership(Studio $studio, User $member): StudioUser
    {
        return StudioUser::where('studio_id', $studio->id)->where('user_id', $member->id)->firstOrFail();
    }
}
