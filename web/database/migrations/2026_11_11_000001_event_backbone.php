<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The event backbone (roadmap step 3):
 * - domain_events: every change worth remembering, written in the same transaction as the change
 *   (an outbox), then relayed to whatever consumes it. It's also the history: statuses elsewhere
 *   are overwritten, events aren't.
 * - message_deliveries: every studio email, with a key that stops it going out twice. Replaces
 *   sent_notifications, which is copied here and left in place for one release.
 * - consent: marketing email opt-in per studio membership.
 * - member_snapshots: each member's activity as of each night, for churn scoring later.
 *
 * Events and deliveries hold IDs and facts, not names or email addresses, and are unlinked when
 * an account is deleted; snapshots are deleted with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('studio_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 60); // booking.attended, payment.failed…
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('payload');
            $table->dateTime('occurred_at');
            $table->dateTime('relayed_at')->nullable();
            $table->unsignedSmallInteger('relay_attempts')->default(0);
            $table->text('relay_error')->nullable();
            $table->index(['studio_id', 'type', 'occurred_at']);
            $table->index(['studio_id', 'user_id', 'occurred_at']);
            $table->index(['relayed_at', 'id']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('domain_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10); // email (sms and push later)
            $table->string('category', 15); // transactional | marketing
            $table->string('kind', 40); // booked, reminder, credits_expiring…
            $table->string('dedupe_key')->unique(); // "reminder:booking:42"
            $table->string('status', 15); // queued | sent | failed | suppressed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['studio_id', 'user_id', 'created_at']);
        });

        Schema::table('studio_users', function (Blueprint $table) {
            $table->dateTime('email_marketing_consent_at')->nullable();
            $table->string('email_marketing_consent_source', 30)->nullable(); // join | profile
            $table->dateTime('email_unsubscribed_at')->nullable();
        });

        Schema::create('member_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('as_of'); // the studio's local date; counts use classes before its midnight
            $table->unsignedInteger('days_since_visit')->nullable(); // null = never attended
            $table->unsignedSmallInteger('visits_28d');
            $table->unsignedSmallInteger('visits_prev_28d');
            $table->unsignedSmallInteger('misses_28d'); // no-shows and late cancels
            $table->unsignedSmallInteger('upcoming_bookings');
            $table->boolean('has_membership');
            $table->unsignedInteger('credits_remaining');
            $table->unsignedSmallInteger('failed_payments_90d');
            $table->unsignedSmallInteger('attendees');
            $table->unsignedInteger('tenure_days');
            $table->dateTime('created_at');
            $table->unique(['studio_id', 'user_id', 'as_of']);
            $table->index(['studio_id', 'as_of']);
        });

        // Emails already sent once (reminders, credit warnings) mustn't go out again.
        $now = now();
        foreach ([
            ['reminder', 'bookings', 'booking'],
            ['credits_expiring', 'entitlements', 'entitlement'],
        ] as [$kind, $table, $ref]) {
            DB::table('sent_notifications')->join($table, "{$table}.id", '=', 'sent_notifications.ref_id')
                ->where('sent_notifications.kind', $kind)
                ->select('sent_notifications.ref_id', 'sent_notifications.sent_at', "{$table}.studio_id", "{$table}.user_id")
                ->orderBy('sent_notifications.id')
                ->chunk(500, function ($rows) use ($kind, $ref, $now) {
                    DB::table('message_deliveries')->insertOrIgnore($rows->map(fn ($r) => [
                        'studio_id' => $r->studio_id,
                        'user_id' => $r->user_id,
                        'channel' => 'email',
                        'category' => 'transactional',
                        'kind' => $kind,
                        'dedupe_key' => "{$kind}:{$ref}:{$r->ref_id}",
                        'status' => 'sent',
                        'sent_at' => $r->sent_at,
                        'created_at' => $r->sent_at ?? $now,
                        'updated_at' => $now,
                    ])->all());
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_snapshots');
        Schema::table('studio_users', function (Blueprint $table) {
            $table->dropColumn(['email_marketing_consent_at', 'email_marketing_consent_source', 'email_unsubscribed_at']);
        });
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('domain_events');
    }
};
