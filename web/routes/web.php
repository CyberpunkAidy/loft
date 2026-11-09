<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\StudioSignupController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Studio;
use App\Models\Automation;
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
        Route::get('/shop', [Studio\ShopController::class, 'index'])->name('shop');
        Route::post('/shop/cart', [Studio\ShopController::class, 'add'])->middleware('throttle:60,1')->name('shop.add');
        Route::get('/shop/cart', [Studio\ShopController::class, 'cart'])->name('shop.cart');
        Route::post('/shop/cart/{variant}', [Studio\ShopController::class, 'update'])->whereNumber('variant')->name('shop.update');
        Route::get('/videos', [Studio\VideoController::class, 'index'])->name('videos');
        Route::get('/videos/{video}', [Studio\VideoController::class, 'show'])->whereNumber('video')->name('videos.show');
        Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
        Route::get('/join', [Studio\JoinController::class, 'create'])->name('join');
        Route::view('/checkout/return', 'studio.checkout-return')->name('checkout.return'); // the app's purchases end here
        // The check-in kiosk: a browser paired with a device code (no one signed in).
        Route::get('/kiosk', [Studio\KioskController::class, 'show'])->name('kiosk');
        Route::post('/kiosk', [Studio\KioskController::class, 'scan'])->middleware('throttle:120,1')->name('kiosk.scan');
        Route::post('/kiosk/pair', [Studio\KioskController::class, 'pair'])->middleware('throttle:10,1')->name('kiosk.pair');
        Route::post('/kiosk/unpair', [Studio\KioskController::class, 'unpair'])->name('kiosk.unpair');
        Route::post('/join', [Studio\JoinController::class, 'store'])->middleware('throttle:10,1')->name('join.store');
        // Signed links from marketing emails; no sign-in needed. CSRF is off for these (see bootstrap/app.php).
        Route::get('/unsubscribe/{member}', [Studio\UnsubscribeController::class, 'show'])->middleware('signed')->name('unsubscribe');
        Route::post('/unsubscribe/{member}', [Studio\UnsubscribeController::class, 'store'])->middleware(['signed', 'throttle:20,1'])->name('unsubscribe.store');

        // Members
        Route::middleware(['auth', 'studio.role:member'])->group(function () {
            Route::get('/account', [Studio\AccountController::class, 'upcoming'])->name('account');
            Route::get('/account/history', [Studio\AccountController::class, 'history'])->name('account.history');
            Route::get('/account/family', [Studio\AccountController::class, 'family'])->name('account.family');
            Route::get('/account/billing', [Studio\AccountController::class, 'billing'])->name('account.billing');
            Route::get('/account/waivers', [Studio\AccountController::class, 'waivers'])->name('account.waivers');
            Route::get('/account/check-in', [Studio\CheckInCodeController::class, 'show'])->name('account.check-in');
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
            Route::get('/bookings/{booking}/join', [Studio\BookingController::class, 'join'])->name('bookings.join');

            Route::post('/checkout/{plan}', [Studio\CheckoutController::class, 'start'])->name('checkout');
            Route::post('/shop/checkout', [Studio\ShopController::class, 'checkout'])->middleware('throttle:20,1')->name('shop.checkout');
            Route::get('/shop/complete', [Studio\ShopController::class, 'complete'])->name('shop.complete');
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
                Route::post('bookings/{booking}/apparatus', [Admin\SessionController::class, 'moveApparatus'])->name('bookings.apparatus');

                Route::resource('rooms', Admin\RoomController::class)->except('show');

                Route::resource('seasons', Admin\SeasonController::class)->except(['show', 'create']);
                Route::resource('plans', Admin\PlanController::class)->except('show');
                Route::post('plans/{plan}/stripe', [Admin\PlanController::class, 'sync'])->name('plans.sync');

                $triggers = array_keys(Automation::TRIGGERS);
                Route::get('automations', [Admin\AutomationController::class, 'index'])->name('automations.index');
                Route::get('automations/{trigger}', [Admin\AutomationController::class, 'edit'])->whereIn('trigger', $triggers)->name('automations.edit');
                Route::put('automations/{trigger}', [Admin\AutomationController::class, 'update'])->whereIn('trigger', $triggers)->name('automations.update');
                Route::post('automations/{trigger}/test', [Admin\AutomationController::class, 'test'])->whereIn('trigger', $triggers)->middleware('throttle:10,1')->name('automations.test');

                Route::get('members', [Admin\MemberController::class, 'index'])->name('members.index');
                Route::get('members/{member}', [Admin\MemberController::class, 'show'])->name('members.show');
                Route::post('members/{member}/plans', [Admin\MemberController::class, 'grant'])->name('members.grant');
                Route::post('members/{member}/tags', [Admin\AccessController::class, 'storeTag'])->name('members.tags.store');
                Route::post('tags/{accessCredential}/revoke', [Admin\AccessController::class, 'revokeTag'])->name('tags.revoke');
                Route::get('access', [Admin\AccessController::class, 'index'])->name('access.index');
                Route::post('access/devices', [Admin\AccessController::class, 'storeDevice'])->name('access.devices.store');
                Route::post('access/devices/{accessDevice}/revoke', [Admin\AccessController::class, 'revokeDevice'])->name('access.devices.revoke');

                Route::get('waivers/download', [Admin\WaiverController::class, 'download'])->name('waivers.download');
                Route::resource('waivers', Admin\WaiverController::class)->except(['show', 'destroy']);
                Route::post('waivers/{waiver}/activate', [Admin\WaiverController::class, 'activate'])->name('waivers.activate');

                Route::get('staff', [Admin\StaffController::class, 'index'])->name('staff.index');
                Route::post('staff', [Admin\StaffController::class, 'store'])->name('staff.store');
                Route::patch('staff/{studioUser}', [Admin\StaffController::class, 'update'])->name('staff.update');
                Route::delete('staff/{studioUser}', [Admin\StaffController::class, 'destroy'])->name('staff.destroy');

                Route::resource('videos', Admin\VideoController::class)->except(['show', 'create']);
                Route::get('videos/{video}/status', [Admin\VideoController::class, 'status'])->name('videos.status');
                Route::resource('products', Admin\ProductController::class)->except('show');
                Route::get('pos', [Admin\PointOfSaleController::class, 'show'])->name('pos');
                Route::post('pos/items', [Admin\PointOfSaleController::class, 'add'])->name('pos.add');
                Route::post('pos/items/{line}', [Admin\PointOfSaleController::class, 'quantity'])->whereNumber('line')->name('pos.quantity');
                Route::post('pos/customer', [Admin\PointOfSaleController::class, 'customer'])->name('pos.customer');
                Route::post('pos/clear', [Admin\PointOfSaleController::class, 'clear'])->name('pos.clear');
                Route::post('pos/checkout', [Admin\PointOfSaleController::class, 'checkout'])->name('pos.checkout');
                Route::get('pos/orders/{order}', [Admin\PointOfSaleController::class, 'receipt'])->name('pos.receipt');
                Route::post('pos/reader', [Admin\PointOfSaleController::class, 'charge'])->middleware('throttle:30,1')->name('pos.charge');
                Route::get('pos/orders/{order}/reader', [Admin\PointOfSaleController::class, 'reader'])->name('pos.reader');
                Route::post('pos/orders/{order}/reader/cancel', [Admin\PointOfSaleController::class, 'cancelReader'])->name('pos.reader.cancel');
                Route::post('pos/orders/{order}/reader/retry', [Admin\PointOfSaleController::class, 'retryReader'])->name('pos.reader.retry');
                Route::post('pos/orders/{order}/reader/simulate', [Admin\PointOfSaleController::class, 'simulateReader'])->name('pos.reader.simulate');
                Route::post('orders/{order}/void', [Admin\PointOfSaleController::class, 'void'])->name('orders.void');

                Route::get('sales', [Admin\SalesController::class, 'index'])->name('sales.index');
                Route::get('sales/export', [Admin\SalesController::class, 'export'])->name('sales.export');
                Route::post('orders/{order}/picked-up', [Admin\SalesController::class, 'pickedUp'])->name('orders.picked_up');

                Route::get('payments', [Admin\PaymentsController::class, 'show'])->name('payments');
                Route::post('payments/connect', [Admin\PaymentsController::class, 'connect'])->name('payments.connect');
                Route::get('payments/readers', [Admin\CardReaderController::class, 'index'])->name('payments.readers');
                Route::post('payments/readers/location', [Admin\CardReaderController::class, 'location'])->name('payments.readers.location');
                Route::post('payments/readers', [Admin\CardReaderController::class, 'store'])->middleware('throttle:20,1')->name('payments.readers.store');
                Route::delete('payments/readers/{cardReader}', [Admin\CardReaderController::class, 'destroy'])->name('payments.readers.destroy');
                Route::get('payments/return', [Admin\PaymentsController::class, 'return'])->name('payments.return');
                Route::post('payments/{payment}/void', [Admin\PaymentsController::class, 'void'])->name('payments.void');

                Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings');
                Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
            });
        });
    });
