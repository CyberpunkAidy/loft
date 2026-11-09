<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| The mobile app's API, version 1 (roadmap step 7): /api/v1/…, JSON, Sanctum bearer tokens.
| Studio routes are scoped to the studio in the URL, like the website's. See the API page in the wiki.
*/
Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    Route::post('auth/tokens', [V1\TokenController::class, 'store'])->middleware('throttle:auth')->name('tokens.store');

    // Check-in kiosks and readers, with a device token (not a member's).
    Route::prefix('devices')->name('access.')->middleware('device')->group(function () {
        Route::get('me', [V1\CheckInController::class, 'device'])->name('device');
        Route::post('check-ins', [V1\CheckInController::class, 'store'])->middleware('idempotent')->name('check-ins');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('auth/tokens/current', [V1\TokenController::class, 'destroy'])->name('tokens.destroy');
        Route::get('me', [V1\MeController::class, 'show'])->name('me');
        Route::patch('me', [V1\MeController::class, 'update'])->name('me.update');
        Route::post('me/devices', [V1\DeviceController::class, 'store'])->name('devices.store');
        Route::delete('me/devices/{device}', [V1\DeviceController::class, 'destroy'])->name('devices.destroy');
    });

    Route::prefix('studios/{studio}')->name('studios.')->middleware('studio')->scopeBindings()->group(function () {
        // Anyone (a token adds the member's own bookings to the schedule)
        Route::get('/', [V1\StudioController::class, 'show'])->name('show');
        Route::get('plans', [V1\StudioController::class, 'plans'])->name('plans');
        Route::get('sessions', [V1\SessionController::class, 'index'])->name('sessions.index');
        Route::get('sessions/{session}', [V1\SessionController::class, 'show'])->name('sessions.show');
        Route::post('register', [V1\MembershipController::class, 'register'])->middleware('throttle:auth')->name('register');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('join', [V1\MembershipController::class, 'join'])->name('join');

            Route::middleware('studio.role:member')->group(function () {
                Route::get('bookings', [V1\BookingController::class, 'index'])->name('bookings.index');
                Route::post('sessions/{session}/bookings', [V1\BookingController::class, 'store'])->middleware('idempotent')->name('bookings.store');
                Route::delete('bookings/{booking}', [V1\BookingController::class, 'destroy'])->name('bookings.destroy');
                Route::post('bookings/{booking}/join', [V1\BookingController::class, 'join'])->name('bookings.join');

                Route::get('attendees', [V1\AttendeeController::class, 'index'])->name('attendees.index');
                Route::post('attendees', [V1\AttendeeController::class, 'store'])->middleware('idempotent')->name('attendees.store');
                Route::patch('attendees/{attendee}', [V1\AttendeeController::class, 'update'])->name('attendees.update');
                Route::delete('attendees/{attendee}', [V1\AttendeeController::class, 'destroy'])->name('attendees.destroy');

                Route::get('waivers', [V1\WaiverController::class, 'index'])->name('waivers.index');
                Route::post('attendees/{attendee}/waiver-signatures', [V1\WaiverController::class, 'store'])->middleware('idempotent')->name('waivers.sign');
                Route::get('attendees/{attendee}/check-in-code', [V1\CheckInController::class, 'code'])->name('check-in-code');

                Route::get('entitlements', [V1\PurchaseController::class, 'entitlements'])->name('entitlements');
                Route::get('orders', [V1\PurchaseController::class, 'orders'])->name('orders');
                Route::post('checkouts', [V1\CheckoutController::class, 'store'])->middleware(['idempotent', 'throttle:20,1'])->name('checkouts.store');
                Route::get('checkouts/{checkout}', [V1\CheckoutController::class, 'show'])->name('checkouts.show');
            });
        });
    });
});
