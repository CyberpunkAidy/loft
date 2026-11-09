<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CardReader;
use App\Models\Studio;
use App\Services\CardReaders;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin → Payments → Card readers: the studio's address for Stripe, then pairing and removing readers. */
class CardReaderController extends Controller
{
    /** Countries where Stripe Terminal sells readers. */
    public const COUNTRIES = [
        'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'SG' => 'Singapore', 'AT' => 'Austria', 'BE' => 'Belgium', 'DE' => 'Germany', 'ES' => 'Spain', 'FR' => 'France', 'IT' => 'Italy', 'NL' => 'Netherlands',
    ];

    public function index(Studio $studio, CardReaders $readers, StripeClient $stripe): View
    {
        $live = [];
        $error = null;
        if ($studio->stripeReady() && $studio->stripe_terminal_location_id) {
            try {
                $live = $readers->sync($studio);
            } catch (StripeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('admin.readers', [
            'readers' => $studio->cardReaders()->get(),
            'live' => $live,
            'error' => $error,
            'testMode' => $stripe->testMode(),
            'countries' => self::COUNTRIES,
        ]);
    }

    public function location(Request $request, Studio $studio, CardReaders $readers): RedirectResponse
    {
        if (! $studio->stripeReady()) {
            return back()->with('error', 'Connect Stripe first.');
        }
        $data = $request->validate([
            'line1' => ['required', 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(self::COUNTRIES))],
        ]);
        try {
            $readers->setLocation($studio, $data);
        } catch (StripeException $e) {
            return back()->withInput()->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->route('studio.admin.payments.readers')->with('success', 'Address saved. Now pair your first reader.');
    }

    public function store(Request $request, Studio $studio, CardReaders $readers): RedirectResponse
    {
        if (! $studio->stripeReady() || ! $studio->stripe_terminal_location_id) {
            return back()->with('error', 'Add the studio’s address first.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:80'],
        ]);
        try {
            $reader = $readers->register($studio, trim($data['code']), trim($data['label']));
        } catch (StripeException $e) {
            return back()->withInput()->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->route('studio.admin.payments.readers')->with('success', "{$reader->label} is paired. It’s ready at the point of sale.");
    }

    public function destroy(Studio $studio, CardReader $cardReader, CardReaders $readers): RedirectResponse
    {
        if ($studio->orders()->where('card_reader_id', $cardReader->id)->where('status', 'pending')->exists()) {
            return back()->with('error', "{$cardReader->label} is waiting for a card. Finish or cancel that sale first.");
        }
        try {
            $readers->remove($cardReader);
        } catch (StripeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return back()->with('success', "{$cardReader->label} was removed.");
    }
}
