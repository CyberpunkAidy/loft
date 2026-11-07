<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loft's tables. Every studio-owned row carries studio_id; all datetimes are UTC.
 * Mirrors the WordPress plugin's schema (see ../ARCHITECTURE.md) with tenancy added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studios', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('timezone', 64)->default('America/Denver');
            $table->string('currency', 3)->default('usd');
            $table->string('accent_color', 7)->default('#c2410c');
            $table->string('contact_email')->nullable();
            $table->string('email_from_name')->nullable();
            $table->json('settings')->nullable();
            $table->string('stripe_account_id', 64)->nullable()->unique();
            $table->boolean('stripe_charges_enabled')->default(false);
            $table->boolean('stripe_details_submitted')->default(false);
            $table->string('stripe_portal_configuration_id', 64)->nullable();
            $table->timestamps();
        });

        // A user's role in a studio. Users are global; one login works across studios.
        Schema::create('studio_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // owner|admin|instructor|member
            $table->string('stripe_customer_id', 64)->nullable();
            $table->timestamps();
            $table->unique(['studio_id', 'user_id']);
            $table->index(['studio_id', 'role']);
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->index(['studio_id', 'is_current']);
        });

        // Age groups, levels and plan tags (the plugin's three taxonomies).
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // age_group|level|plan_tag
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->timestamps();
            $table->unique(['studio_id', 'type', 'slug']);
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->text('description')->nullable();
            $table->string('type', 10)->default('class'); // class|event
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable(); // 0 = Sunday … 6 = Saturday
            $table->string('start_time', 5)->nullable(); // HH:MM, studio local time
            $table->unsignedSmallInteger('duration_min')->default(60);
            $table->date('event_date')->nullable();
            $table->unsignedSmallInteger('capacity')->default(10);
            $table->unsignedInteger('dropin_price_cents')->nullable();
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            $table->boolean('members_only')->default(false);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->index(['studio_id', 'is_published']);
        });

        Schema::create('category_program', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'program_id']);
        });

        // "sessions" is taken by Laravel's session store.
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity')->default(10);
            $table->string('status', 20)->default('scheduled'); // scheduled|cancelled
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('price_cents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['studio_id', 'starts_at']);
            $table->index(['program_id', 'starts_at']);
        });

        Schema::create('attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the account holder
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('dob')->nullable();
            $table->boolean('is_self')->default(false);
            $table->text('medical_notes')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('status', 20)->default('active'); // active|archived
            $table->timestamps();
            $table->index(['studio_id', 'user_id']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 20); // subscription|season|pack|dropin
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('billing_interval', 10)->nullable();
            $table->unsignedSmallInteger('credits')->nullable();
            $table->unsignedSmallInteger('credit_validity_days')->nullable();
            $table->unsignedSmallInteger('weekly_limit')->nullable();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('registration_fee_cents')->default(0);
            $table->string('stripe_product_id', 64)->nullable();
            $table->string('stripe_price_id', 64)->nullable();
            $table->string('stripe_fee_price_id', 64)->nullable();
            $table->json('program_scope')->nullable(); // null = all programs, else plan tag slugs
            $table->boolean('active')->default(true);
            $table->smallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['studio_id', 'active']);
        });

        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // subscription|credits
            $table->string('status', 20)->default('active'); // active|past_due|cancelled|expired
            $table->string('stripe_subscription_id', 64)->nullable()->unique();
            $table->string('stripe_customer_id', 64)->nullable();
            $table->string('stripe_checkout_session_id', 90)->nullable()->unique();
            $table->unsignedSmallInteger('credits_total')->nullable();
            $table->smallInteger('credits_remaining')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->json('program_scope')->nullable();
            $table->unsignedSmallInteger('weekly_limit')->nullable();
            $table->timestamps();
            $table->index(['studio_id', 'user_id', 'status']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('booked'); // booked|waitlisted|cancelled|late_cancelled|no_show|attended
            $table->unsignedSmallInteger('position')->nullable(); // waitlist order
            $table->boolean('credit_consumed')->default(false);
            $table->string('source', 20)->default('web'); // web|admin|waitlist_promote
            $table->dateTime('booked_at');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('attended_at')->nullable();
            $table->timestamps();
            $table->index(['class_session_id', 'status']);
            $table->index(['studio_id', 'user_id', 'status']);
            $table->index(['entitlement_id', 'status']);
        });

        Schema::create('waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['studio_id', 'is_active']);
        });

        Schema::create('waiver_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('waiver_version');
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('signed_name');
            $table->string('signature_path')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->dateTime('signed_at');
            $table->timestamps();
            $table->index(['attendee_id', 'waiver_id', 'waiver_version']);
        });

        // Idempotency log for Stripe webhooks.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('type', 80);
            $table->string('account', 64)->nullable();
            $table->foreignId('studio_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->longText('payload')->nullable();
            $table->timestamps();
        });

        // Makes reminder and expiry emails send once.
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->unsignedBigInteger('ref_id');
            $table->dateTime('sent_at');
            $table->unique(['kind', 'ref_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'sent_notifications', 'stripe_events', 'waiver_signatures', 'waivers', 'bookings', 'entitlements',
            'plans', 'attendees', 'class_sessions', 'category_program', 'programs', 'categories', 'seasons',
            'studio_users', 'studios',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
