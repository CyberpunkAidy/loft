<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\StudioSignupController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Studio;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform (the marketing site, sign-in, and studio sign-up)
|--------------------------------------------------------------------------
*/
Route::get('/', [PlatformController::class, 'home'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->name('password.update');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/signup', [StudioSignupController::class, 'create'])->name('signup');
Route::post('/signup', [StudioSignupController::class, 'store'])->middleware('throttle:10,1')->name('signup.store');
Route::get('/studios', [PlatformController::class, 'studios'])->middleware('auth')->name('studios');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

/*
|--------------------------------------------------------------------------
| A studio: /{studio}
|--------------------------------------------------------------------------
| scopeBindings() resolves every child ({program}, {session}, {booking}…) through the studio,
| so one studio's URLs can never load another studio's rows.
*/
Route::prefix('{studio}')
    ->where(['studio' => '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?'])
    ->middleware('studio')
    ->scopeBindings()
    ->name('studio.')
    ->group(function () {
        // Public
        Route::get('/', [Studio\ScheduleController::class, 'index'])->name('schedule');
        Route::get('/classes/{program}', [Studio\ScheduleController::class, 'program'])->name('program');
        Route::get('/plans', [Studio\PlanController::class, 'index'])->name('plans');
        Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
        Route::get('/join', [Studio\JoinController::class, 'create'])->name('join');
        Route::post('/join', [Studio\JoinController::class, 'store'])->middleware('throttle:10,1')->name('join.store');

        // Members
        Route::middleware(['auth', 'studio.role:member'])->group(function () {
            Route::get('/account', [Studio\AccountController::class, 'upcoming'])->name('account');
            Route::get('/account/history', [Studio\AccountController::class, 'history'])->name('account.history');
            Route::get('/account/family', [Studio\AccountController::class, 'family'])->name('account.family');
            Route::get('/account/billing', [Studio\AccountController::class, 'billing'])->name('account.billing');
            Route::get('/account/waivers', [Studio\AccountController::class, 'waivers'])->name('account.waivers');
            Route::get('/account/profile', [Studio\AccountController::class, 'profile'])->name('account.profile');
            Route::put('/account/profile', [Studio\AccountController::class, 'updateProfile'])->name('account.profile.update');
            Route::delete('/account', [Studio\AccountController::class, 'destroy'])->name('account.destroy');

            Route::get('/account/family/new', [Studio\AttendeeController::class, 'create'])->name('attendees.create');
            Route::post('/account/family', [Studio\AttendeeController::class, 'store'])->name('attendees.store');
            Route::get('/account/family/{attendee}', [Studio\AttendeeController::class, 'edit'])->name('attendees.edit');
            Route::put('/account/family/{attendee}', [Studio\AttendeeController::class, 'update'])->name('attendees.update');
            Route::post('/account/family/{attendee}/archive', [Studio\AttendeeController::class, 'archive'])->name('attendees.archive');

            Route::get('/waiver/{attendee}', [Studio\WaiverController::class, 'show'])->name('waiver');
            Route::post('/waiver/{attendee}', [Studio\WaiverController::class, 'sign'])->name('waiver.sign');

            Route::get('/book/{session}', [Studio\BookingController::class, 'create'])->name('book');
            Route::post('/book/{session}', [Studio\BookingController::class, 'store'])->name('book.store');
            Route::post('/bookings/{booking}/cancel', [Studio\BookingController::class, 'cancel'])->name('bookings.cancel');

            Route::post('/checkout/{plan}', [Studio\CheckoutController::class, 'start'])->name('checkout');
            Route::get('/checkout/complete', [Studio\CheckoutController::class, 'complete'])->name('checkout.complete');
            Route::post('/billing-portal', [Studio\CheckoutController::class, 'portal'])->name('billing.portal');
        });

        // Staff
        Route::prefix('admin')->name('admin.')->middleware(['auth', 'studio.role:instructor'])->group(function () {
            Route::get('/', Admin\DashboardController::class)->name('dashboard');
            Route::get('/checkin', [Admin\CheckinController::class, 'index'])->name('checkin');
            Route::post('/checkin/{booking}', [Admin\CheckinController::class, 'mark'])->name('checkin.mark');

            Route::middleware('studio.role:admin')->group(function () {
                Route::resource('programs', Admin\ProgramController::class)->except('show');
                Route::post('programs/{program}/sessions', [Admin\ProgramController::class, 'sync'])->name('programs.sync');

                Route::get('sessions', [Admin\SessionController::class, 'index'])->name('sessions.index');
                Route::get('sessions/{session}', [Admin\SessionController::class, 'show'])->name('sessions.show');
                Route::patch('sessions/{session}', [Admin\SessionController::class, 'update'])->name('sessions.update');
                Route::post('sessions/{session}/cancel', [Admin\SessionController::class, 'cancel'])->name('sessions.cancel');
                Route::delete('sessions/{session}', [Admin\SessionController::class, 'destroy'])->name('sessions.destroy');
                Route::post('sessions/{session}/bookings', [Admin\SessionController::class, 'book'])->name('sessions.book');
                Route::post('bookings/{booking}/cancel', [Admin\SessionController::class, 'cancelBooking'])->name('bookings.cancel');

                Route::resource('seasons', Admin\SeasonController::class)->except(['show', 'create']);
                Route::resource('plans', Admin\PlanController::class)->except('show');
                Route::post('plans/{plan}/stripe', [Admin\PlanController::class, 'sync'])->name('plans.sync');

                Route::get('members', [Admin\MemberController::class, 'index'])->name('members.index');
                Route::get('members/{member}', [Admin\MemberController::class, 'show'])->name('members.show');
                Route::post('members/{member}/comp', [Admin\MemberController::class, 'comp'])->name('members.comp');

                Route::resource('waivers', Admin\WaiverController::class)->except(['show', 'destroy']);
                Route::post('waivers/{waiver}/activate', [Admin\WaiverController::class, 'activate'])->name('waivers.activate');

                Route::get('staff', [Admin\StaffController::class, 'index'])->name('staff.index');
                Route::post('staff', [Admin\StaffController::class, 'store'])->name('staff.store');
                Route::patch('staff/{studioUser}', [Admin\StaffController::class, 'update'])->name('staff.update');
                Route::delete('staff/{studioUser}', [Admin\StaffController::class, 'destroy'])->name('staff.destroy');

                Route::get('payments', [Admin\PaymentsController::class, 'show'])->name('payments');
                Route::post('payments/connect', [Admin\PaymentsController::class, 'connect'])->name('payments.connect');
                Route::get('payments/return', [Admin\PaymentsController::class, 'return'])->name('payments.return');

                Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings');
                Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
            });
        });
    });
