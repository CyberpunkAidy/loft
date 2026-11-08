# Roadmap

A plan for the next features, drafted on 2026-11-08 from a review of the current code. Nothing here is built yet. It's the agreed direction, with schema sketches to start from.

## Starting point

- **Booking engine (`Bookings`):** one capacity number per session. Every seat change locks the session row. Ordered waitlists promote automatically after re-checking the plan.
- **Waivers:** one active, versioned waiver per studio, signed per attendee, with an expiry. Enforced on member web bookings only. Staff bookings, waitlist promotion and check-in show the status but don't block.
- **Billing:** Stripe Checkout, one plan per purchase, plus payments recorded in person. No products, orders or cart.
- **Events:** in-memory Laravel events feed the email listener. They aren't durable, nothing keeps a history, and there's no attendance event.
- **API:** none. Server-rendered pages with session logins only.

## Principles

1. **Stay a modular monolith:** the same Laravel app and Postgres database, with the same tenancy rules. New modules are services, models and tests, not separate systems.
2. **One service layer, two front doors.** Web controllers and the API both call the same services. Move the orchestration left in `BookingController` and `CheckoutController` into services before building the API.
3. **Durable events.** A transactional outbox: write a `domain_events` row in the same database transaction as the change, then relay it to marketing, analytics, outgoing webhooks and churn scoring. The same table is the history.
4. **Resources, not more capacity numbers.** Apparatus limits and online seats are both sub-capacities of a session, and one model covers both.
5. **One money ledger.** `orders` and `order_items` record every sale, however it was paid.
6. **Rules first, AI later.** Collect point-in-time snapshots now; score with rules; train a model once there's labelled history.
7. **Integrate, don't build:**
   - **Video:** Mux or Cloudflare Stream.
   - **Card payments in person:** Stripe Terminal.
   - **SMS:** Twilio.
   - **Door hardware:** Kisi or Brivo.
   - **Biometrics:** the scanner vendor's own matching.
8. **Roll out per studio** behind flags in `studios.settings`.

## Features

### Apparatus-level capacity and waitlists

- **Capacity:** a session keeps its room `capacity`, and can add apparatus rows: for example 5 silks with up to 2 people each.
- **Booking:** a booking picks an apparatus type (automatically when there's only one).
- **The check:** inside the existing session lock, a booking is full when either the room total or that apparatus type is full.
- **Waitlist entries** remember their type. Promotion takes the first waiting entry whose type has a free spot.
- **Optional:** check that overlapping sessions don't need more units than the studio owns.

### Waivers

- **Required set:** each class has a set of required waivers: studio-wide ones plus ones attached to programs (for example an aerial liability waiver).
- **Enforced everywhere:** web booking (as today), waitlist promotion, staff booking (with a logged override), check-in and kiosks.
- **Evidence:** store a hash of the exact text signed.
- **Retention:** keep signatures when accounts are deleted, unlinked but named (see [Decisions](Decisions#open-questions)).

### Point of sale and one checkout

- **Orders:** `orders` with mixed `order_items` (plan, drop-in or product).
- **Online:** one Stripe Checkout per order. Subscription mode also accepts one-time items, charged on the first invoice. Allow one membership per cart.
- **Front desk:** a point-of-sale screen that charges a Stripe Terminal reader, or records cash the way in-person payments work today.
- **Fulfilment:** item by item: grant the plan, decrement stock.
- **Merchandise:** needs sales tax (Stripe Tax). Start with in-studio pickup only.

### Event-driven marketing

- **Pipeline:** outbox events feed `automations` (rules each studio sets), and every send is logged in `message_deliveries` with a key that prevents duplicates.
- **Consent:** stored per studio. Booking confirmations are transactional. Birthdays and win-back messages are marketing, and need opt-in plus an unsubscribe link (CAN-SPAM). Marketing texts need prior written consent (TCPA).
- **Milestones:**
  - **First class:** a new `booking.attended` event.
  - **Birthday:** a daily check of `attendees.dob` in the studio's time zone; children's birthdays message the parent.
  - **Churn risk:** a score crossing a threshold.

### Churn prediction

- **The gap:** today statuses are overwritten, so history is lost. Record events now.
- **Snapshots:** each night, save each member's features into `member_snapshots`, using only data available as of that date. Example query:

```sql
-- Features per member as of :as_of (only sessions before :as_of count)
SELECT su.studio_id, su.user_id,
  :as_of::date - MAX(cs.starts_at)::date FILTER (WHERE b.status = 'attended')                       AS days_since_visit,
  COUNT(*) FILTER (WHERE b.status = 'attended' AND cs.starts_at >= :as_of - INTERVAL '28 days')     AS visits_28d,
  COUNT(*) FILTER (WHERE b.status = 'attended' AND cs.starts_at <  :as_of - INTERVAL '28 days'
                                              AND cs.starts_at >= :as_of - INTERVAL '56 days')      AS visits_prev_28d,
  COUNT(*) FILTER (WHERE b.status IN ('late_cancelled', 'no_show')
                   AND cs.starts_at >= :as_of - INTERVAL '28 days')                                  AS misses_28d,
  (SELECT COUNT(*) FROM domain_events e WHERE e.studio_id = su.studio_id AND e.user_id = su.user_id
     AND e.type = 'payment.failed' AND e.occurred_at BETWEEN :as_of - INTERVAL '90 days' AND :as_of) AS failed_payments_90d
FROM studio_users su
LEFT JOIN bookings b ON b.studio_id = su.studio_id AND b.user_id = su.user_id
LEFT JOIN class_sessions cs ON cs.id = b.class_session_id AND cs.starts_at < :as_of
GROUP BY su.studio_id, su.user_id;
```

- **Scores:** go in `member_risk_scores` (with `model_version` and `reasons`). Automations read only that table, so replacing the rules with a model changes nothing downstream.
- **Privacy:** pseudonymise IDs before data leaves the database.

### API for a native mobile app

- **Auth and shape:** Sanctum tokens (`php artisan install:api`), versioned URLs (`/api/v1`), scoped to the studio like the web routes.
- **Errors:** stable codes from `BookingException` reasons (`waiver_required`, `needs_plan`, `full`).
- **Requests:** an `Idempotency-Key` on bookings and purchases, cursor pagination, UTC timestamps plus the studio's time zone.

```php
Route::prefix('v1')->group(function () {
    Route::post('auth/tokens', [Api\TokenController::class, 'store']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [Api\MeController::class, 'show']);
        Route::post('me/devices', [Api\DeviceController::class, 'store']);         // push tokens
        Route::prefix('studios/{studio}')->middleware('studio')->scopeBindings()->group(function () {
            Route::get('sessions', [Api\SessionController::class, 'index']);
            Route::post('sessions/{session}/bookings', [Api\BookingController::class, 'store']);
            Route::delete('bookings/{booking}', [Api\BookingController::class, 'destroy']);
            Route::post('attendees/{attendee}/waiver-signatures', [Api\WaiverController::class, 'store']);
            Route::post('videos/{video}/playback', [Api\VideoController::class, 'store']); // short-lived signed URL
        });
    });
});
```

- **On-demand video:** a `videos` table pointing at the video provider's assets. Access comes from plans.
- **Live classes:** `class_sessions.delivery` set to in-person, virtual or hybrid. Online seats are another resource in the apparatus model.
- **Apple's rules:** digital content sold inside an iOS app falls under Apple's in-app purchase rules, which changed for US apps in 2025. Check the current terms before deciding how the app sells video.
- **The app itself:** a React Native (Expo) app against this API is the recommended route ([Decisions](Decisions#tech-stack-a-proposal-to-move-to-nextjs-and-node)).

### Contactless check-in

- **Devices:** kiosks and readers have their own studio-scoped API tokens (ability `checkin`). They aren't user accounts.
- **Credentials:** members hold an RFID tag, NFC, a rotating QR code in the app, or a biometric reference.
- **Log:** every scan writes an `access_events` row, whether allowed or denied.
- **Biometrics:** store only the vendor's opaque match ID, never fingerprint or face data. Biometric privacy laws (Illinois BIPA, Texas, Washington, GDPR Article 9) require written consent and a retention policy. Ship QR and NFC first.

```http
POST /api/v1/devices/check-ins           Authorization: Bearer <device token>   Idempotency-Key: <uuid>
{"credential": {"kind": "rfid", "value": "04A2…"}, "occurred_at": "2026-11-09T17:58:00Z"}

200 {"result": "granted", "attendee": {"id": 12, "first_name": "Maya"}, "booking_id": 345}
200 {"result": "denied", "reason": "waiver_required", "message": "Maya needs to sign the aerial waiver."}
```

## Schema sketches

These follow the existing conventions: `studio_id` on every studio-owned row, UTC `dateTime` columns.

**Apparatus:**
```php
Schema::create('apparatus_types', function (Blueprint $t) {
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->string('name', 60);                                   // Silks, Lyra, Trapeze
    $t->unsignedSmallInteger('units_owned');                  // 5
    $t->unsignedTinyInteger('default_per_unit')->default(1);  // 2 people per silk
    $t->timestamps();
    $t->unique(['studio_id', 'name']);
});
Schema::create('program_apparatus', function (Blueprint $t) {        // the template a program's sessions copy
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->foreignId('program_id')->constrained()->cascadeOnDelete();
    $t->foreignId('apparatus_type_id')->constrained('apparatus_types')->cascadeOnDelete();
    $t->unsignedSmallInteger('units');
    $t->unsignedTinyInteger('per_unit');
    $t->unique(['program_id', 'apparatus_type_id']);
});
Schema::create('session_apparatus', function (Blueprint $t) {        // per session, editable
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->foreignId('class_session_id')->constrained()->cascadeOnDelete();
    $t->foreignId('apparatus_type_id')->constrained('apparatus_types')->cascadeOnDelete();
    $t->unsignedSmallInteger('units');
    $t->unsignedTinyInteger('per_unit');
    $t->unique(['class_session_id', 'apparatus_type_id']);
});
Schema::table('bookings', function (Blueprint $t) {
    $t->foreignId('apparatus_type_id')->nullable()->after('attendee_id')->constrained('apparatus_types')->nullOnDelete();
    $t->unsignedSmallInteger('apparatus_unit')->nullable();   // optional rig assignment ("Silk 3")
    $t->index(['class_session_id', 'apparatus_type_id', 'status']);
});
```

**Waivers:**
```php
Schema::table('waivers', function (Blueprint $t) {
    $t->string('scope', 20)->default('studio');               // studio = everyone; program = where attached
    $t->unsignedSmallInteger('validity_days')->nullable();    // overrides the studio setting
});
Schema::create('program_waiver', function (Blueprint $t) {
    $t->foreignId('program_id')->constrained()->cascadeOnDelete();
    $t->foreignId('waiver_id')->constrained()->cascadeOnDelete();
    $t->primary(['program_id', 'waiver_id']);
});
Schema::table('waiver_signatures', function (Blueprint $t) {
    $t->char('body_sha256', 64)->nullable();
    $t->string('signer_relationship', 20)->nullable();        // self | parent | guardian
    $t->string('attendee_name')->nullable();                  // kept if the account is deleted
    // and make attendee_id / signed_by_user_id nullable + nullOnDelete
});
Schema::create('waiver_overrides', function (Blueprint $t) {
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
    $t->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
    $t->string('reason');
    $t->dateTime('created_at');
});
```

**Events and marketing:**
```php
Schema::create('domain_events', function (Blueprint $t) {            // outbox and event history
    $t->id();
    $t->uuid('uuid')->unique();
    $t->foreignId('studio_id')->nullable()->constrained()->cascadeOnDelete();
    $t->string('type', 60);                                   // booking.attended, payment.failed…
    $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $t->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
    $t->jsonb('payload');
    $t->dateTime('occurred_at');
    $t->dateTime('relayed_at')->nullable();
    $t->index(['studio_id', 'type', 'occurred_at']);
    $t->index(['relayed_at']);
});
Schema::create('automations', function (Blueprint $t) {
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->string('trigger', 40);                                // first_class | birthday | churn_risk | no_visit_days…
    $t->jsonb('conditions')->nullable();
    $t->string('channel', 10);                                // email | sms | push
    $t->unsignedInteger('delay_minutes')->default(0);
    $t->string('subject')->nullable();
    $t->text('body');
    $t->boolean('active')->default(false);
    $t->timestamps();
});
Schema::create('message_deliveries', function (Blueprint $t) {       // replaces sent_notifications
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $t->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
    $t->foreignId('domain_event_id')->nullable()->constrained()->nullOnDelete();
    $t->string('channel', 10);
    $t->string('category', 15);                               // transactional | marketing
    $t->string('dedupe_key')->unique();                       // "birthday:2026:attendee:42"
    $t->string('status', 15);                                 // queued | sent | failed | suppressed
    $t->string('provider_message_id')->nullable();
    $t->dateTime('scheduled_for')->nullable();
    $t->dateTime('sent_at')->nullable();
    $t->timestamps();
    $t->index(['studio_id', 'user_id', 'created_at']);
});
Schema::table('studio_users', function (Blueprint $t) {             // consent, per studio
    $t->dateTime('email_marketing_opt_in_at')->nullable();
    $t->dateTime('sms_opt_in_at')->nullable();
    $t->string('sms_opt_in_source', 30)->nullable();
    $t->dateTime('unsubscribed_at')->nullable();
});
```

**Other modules (outline):**
- **Commerce:** `products`, `product_variants`, `orders`, `order_items`, `payments.order_id`.
- **API:** `personal_access_tokens` (Sanctum), `user_devices`.
- **Access:** `access_devices`, `access_credentials`, `access_events`.
- **Churn:** `member_snapshots`, `member_risk_scores`.
- **Virtual classes:** `videos`, `class_sessions.delivery`.

## Order

1. [ ] **Waiver hardening:** program-level waivers, enforcement everywhere, overrides, text hash, retention.
2. [ ] **Apparatus capacity and smarter waitlists:** settle the booking model before anything depends on it.
3. [ ] **Event backbone:** outbox, `booking.attended`, `message_deliveries`, consent. Start the nightly snapshots here too.
4. [ ] **Automations, email first:** first class, birthday, win-back. Start SMS carrier registration in parallel.
5. [ ] **Orders ledger:** route plan checkouts and recorded payments through `orders`.
6. [ ] **Merchandise and point of sale:** products, a shared cart, Stripe Terminal at the front desk.
7. [ ] **API v1:** services first, then Sanctum, schedule, bookings, account, waivers, push tokens.
8. [ ] **Contactless check-in:** devices, credentials and the check-in endpoint, QR and NFC first.
9. [ ] **Churn scoring v1:** rules over the snapshots, feeding automations. A trained model after about six months of labelled history.
10. [ ] **On-demand video and live virtual classes.**

Steps 1 and 2 change rules members feel directly, so test them on one studio behind a flag first.

## Known gaps (smaller)

- **Stripe refunds:** a refund doesn't take back the credits or membership it paid for.
- **Staff bookings:** they skip the waiver check (covered by step 1).
- **Waiver signatures:** deleted with accounts and closed studios (step 1 and [Decisions](Decisions#open-questions)).
- **Merged branches:** they need deleting by hand on GitHub.
