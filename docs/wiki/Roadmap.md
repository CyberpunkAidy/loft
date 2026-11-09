# Roadmap

A plan for the next features, drafted on 2026-11-08 from a review of the current code, and confirmed the same day: Loft stays on Laravel, and rooms and room-based apparatus from the [proposed Prisma schema](Decisions#a-proposed-prisma-schema) are folded in (option A). Steps 1 to 9 are built (step 4 by email; SMS waits on carrier registration; step 8 with QR codes and tags; step 9 with rules); the rest is the agreed direction, with schema sketches to start from.

## Starting point

- **Booking engine (`Bookings`):** every seat change locks the session row. Ordered waitlists promote automatically after re-checking the plan.
- **Rooms and apparatus (step 2, done):**
  - Rooms with a capacity limit, and apparatus rigged in them. Classes use some of their room's apparatus, and each booking takes a numbered spot on one.
  - Waitlists can be capped, and entries wait for the apparatus they asked for.
  - Staff see room clashes as warnings.
- **Waivers (step 1, done):**
  - Several in force at once, for every class or for chosen programs, each with its own validity.
  - Every version's text is kept, and signatures are hashed.
  - Enforced at booking, waitlist promotion, staff booking and check-in, with staff overrides recorded.
  - Signatures are kept when accounts are deleted, and can be downloaded.
- **Billing:** Stripe Checkout, one plan per purchase, plus payments recorded in person. **Orders ledger (step 5, done):** every sale is an order with items ([Payments](Payments#the-orders-ledger)). No products or cart yet.
- **Event backbone (step 3, done):**
  - Every change records a domain event in the same transaction (the outbox), including `booking.attended` with a first-visit flag; a relay hands them to handlers. None are registered yet.
  - Every studio email is logged in `message_deliveries` with a dedupe key.
  - Marketing email consent per studio, with one-click unsubscribe.
  - Nightly member snapshots.
  - See [Architecture](Architecture#the-outbox-domain_events).
- **Automations (step 4, done by email):** after a first class, birthday and we miss you, as marketing email ([Email](Email#automations)). The first-class follow-up is the outbox's first consumer.
- **API (step 7, done):** a JSON API for the mobile app at `/api/v1`, with Sanctum tokens ([API](API)).

## Principles

1. **Stay a modular monolith:** the same Laravel app and Postgres database, with the same tenancy rules. New modules are services, models and tests, not separate systems.
2. **One service layer, two front doors.** Web controllers and the API both call the same services. Move the orchestration left in `BookingController` and `CheckoutController` into services before building the API.
3. **Durable events.** A transactional outbox: write a `domain_events` row in the same database transaction as the change, then relay it to marketing, analytics, outgoing webhooks and churn scoring. The same table is the history.
4. **Resources, not more capacity numbers.** A room's fire-code limit, apparatus limits and online seats are all limits on one session's bookings, and one model covers them.
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

### Rooms, apparatus capacity and waitlists (built)

Built as sketched below, and the overlap check is in, as a warning on the session page and after saving a program. Choices made while building it are [decision 21](Decisions). See [Features](Features#booking-rules-studio-settings) for how it works.

- **Rooms:** a studio has rooms, each with a fire-code `max_capacity`. Programs and sessions belong to a room, and a session's `capacity` can't exceed its room's limit (checked when saving).
- **Apparatus:** what's rigged in a room, for example "Silks: 5, up to 2 people each".
  - **Per class:** a session uses some of its room's apparatus, at most what the room has. A program carries the default, and each session can change it (a rig down for repair, say).
  - **Booking:** a booking picks the apparatus (automatically when the class uses only one). It can record which item, such as "Silk 3", for the roster.
- **The check:** inside the existing session lock, a booking is confirmed only while:
  - the session has room: fewer booked than its capacity;
  - the chosen apparatus has room: fewer booked on it than quantity × people per item.
- **Waitlists:**
  - A session can cap its waitlist (`waitlist_capacity`; empty means no limit).
  - Waitlist entries remember their apparatus, and promotion takes the first waiting entry whose apparatus has a free spot.
- **Optional:** check that overlapping sessions in the same room don't use more apparatus than is rigged.

### Waivers

- **Required set:** each class has a set of required waivers: studio-wide ones plus ones attached to programs (for example an aerial liability waiver).
- **Enforced everywhere:** web booking (as today), waitlist promotion, staff booking (with a logged override), check-in and kiosks.
- **Evidence:** store a hash of the exact text signed.
- **Retention ([decision 20](Decisions)):**
  - Keep signatures when accounts are deleted: unlinked from the account, with the signer's and attendee's names and the exact text signed.
  - Let owners download every signed waiver, for example before closing the studio.

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
- **The app itself:** React Native (Expo) against this API ([decision 17](Decisions#tech-stack-nextjs-and-node)).

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

These follow the existing conventions: `studio_id` on every studio-owned row, UTC `dateTime` columns. The rooms, waiver and event sketches are built; their migrations are the source of truth, and the sketches stay for reference. As built, the events schema differs a little: `message_deliveries` gains `automation_id` with automations (step 4), and SMS consent columns come with SMS.

**Rooms and apparatus:**
```php
Schema::create('rooms', function (Blueprint $t) {          // fire-code limit
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->string('name', 60);
    $t->unsignedSmallInteger('max_capacity');
    $t->timestamps();
    $t->unique(['studio_id', 'name']);
});
Schema::create('apparatus', function (Blueprint $t) {      // what's rigged in a room
    $t->id();
    $t->foreignId('studio_id')->constrained()->cascadeOnDelete();
    $t->foreignId('room_id')->constrained()->cascadeOnDelete();
    $t->string('name', 60);                                // Silks, Lyra, Trapeze, Pole
    $t->unsignedSmallInteger('quantity');                  // 5
    $t->unsignedTinyInteger('max_per_item');               // 2
    $t->timestamps();
});
Schema::create('program_apparatus', function (Blueprint $t) {   // a program's default allocation
    $t->id();
    $t->foreignId('program_id')->constrained()->cascadeOnDelete();
    $t->foreignId('apparatus_id')->constrained('apparatus')->cascadeOnDelete();
    $t->unsignedSmallInteger('quantity');                  // at most the room's
    $t->unsignedTinyInteger('max_per_item');
    $t->unique(['program_id', 'apparatus_id']);
});
Schema::create('session_apparatus', function (Blueprint $t) {   // what this session uses, editable
    $t->id();
    $t->foreignId('class_session_id')->constrained()->cascadeOnDelete();
    $t->foreignId('apparatus_id')->constrained('apparatus')->cascadeOnDelete();
    $t->unsignedSmallInteger('quantity');
    $t->unsignedTinyInteger('max_per_item');
    $t->unique(['class_session_id', 'apparatus_id']);
});
Schema::table('programs', function (Blueprint $t) {
    $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
});
Schema::table('class_sessions', function (Blueprint $t) {
    $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
    $t->unsignedSmallInteger('waitlist_capacity')->nullable();   // null = no limit
});
Schema::table('bookings', function (Blueprint $t) {
    $t->foreignId('apparatus_id')->nullable()->after('attendee_id')->constrained('apparatus')->nullOnDelete();
    $t->unsignedSmallInteger('apparatus_item')->nullable();       // "Silk 3", for the roster
    $t->index(['class_session_id', 'apparatus_id', 'status']);
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

1. [x] **Waiver hardening** (built 2026-11-08, [#15](https://github.com/CyberpunkAidy/loft-studio/pull/15)): program-level waivers, enforcement everywhere, overrides, text hash, keeping signatures, and downloading signed waivers.
2. [x] **Rooms, apparatus capacity and waitlist limits** (built 2026-11-08, [#16](https://github.com/CyberpunkAidy/loft-studio/pull/16)): settles the booking model before anything depends on it.
3. [x] **Event backbone** (built 2026-11-08, [#17](https://github.com/CyberpunkAidy/loft-studio/pull/17)): outbox, `booking.attended`, `message_deliveries`, consent, and the nightly snapshots.
4. [x] **Automations, email first** (built 2026-11-08, [#18](https://github.com/CyberpunkAidy/loft-studio/pull/18)): first class, birthday, win-back. Still to do: SMS, once carrier registration (A2P 10DLC) is through; it's a business task, so start it early. Each studio will connect its own Twilio account ([decision 24](Decisions)).
5. [x] **Orders ledger** (built 2026-11-09, [#19](https://github.com/CyberpunkAidy/loft-studio/pull/19)): plan checkouts, membership invoices and recorded payments all go through `orders`.
6. [x] **Merchandise and point of sale** (built 2026-11-09):
   - [x] Products with variants and stock, sales tax on products, and the front-desk point of sale paid in person ([decision 26](Decisions), [#20](https://github.com/CyberpunkAidy/loft-studio/pull/20)).
   - [x] An online shop: a cart of products paid through Stripe Checkout, stock held while paying, picked up at the studio ([decision 27](Decisions), [#21](https://github.com/CyberpunkAidy/loft-studio/pull/21)). Plans keep their own Checkout.
   - [x] Stripe Terminal card readers at the point of sale: paired by code, charged from the till, refunds from Loft ([decision 28](Decisions), [#22](https://github.com/CyberpunkAidy/loft-studio/pull/22)).
7. [x] **API v1** (built 2026-11-09, [decision 29](Decisions), [#23](https://github.com/CyberpunkAidy/loft-studio/pull/23)): services first, then Sanctum, schedule, bookings, account, waivers, purchases and checkout, push tokens ([API](API)). Sending push notifications comes with the app.
8. [x] **Contactless check-in** (built 2026-11-09, [decision 30](Decisions), [#24](https://github.com/CyberpunkAidy/loft-studio/pull/24)): devices with their own tokens, NFC/RFID tags, rotating QR codes, the check-in endpoint and a browser kiosk, every scan logged. Biometrics and door hardware (Kisi, Brivo) later.
9. [x] **Churn scoring v1** (built 2026-11-09, [decision 31](Decisions)): rules over the snapshots into `member_risk_scores`, the **At risk of leaving** email, risk on the members list, dashboard and member pages, and a pseudonymised training export. Still to do: a trained model after about six months of labelled history.
10. [ ] **On-demand video and live virtual classes.**

Steps 1 and 2 change rules members feel directly. Rooms are opt-in: a studio without rooms books exactly as before.

## Known gaps (smaller)

- **Stripe refunds:** a refund doesn't take back the credits or membership it paid for.
- **An old table:** `sent_notifications` is no longer written (replaced by `message_deliveries`). Drop it in a release after the event backbone is live.
- **Merged branches:** they need deleting by hand on GitHub.
