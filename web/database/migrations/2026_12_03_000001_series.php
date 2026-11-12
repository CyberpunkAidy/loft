<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Series sign-up (decision 47): a run of a program's classes (an 8-week series, an intro course, a
 * semester) sold as one, paid in full, which books the person into every class in it.
 *
 * - series: the run, by date range; its classes point back at it (class_sessions.series_id).
 * - series_enrollments: one person in one series. "pending" holds their spot while they pay;
 *   "active" once paid (their classes are booked); "withdrawn" or "released" after.
 * - Bookings, order lines and make-up credits point at the enrollment they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('join_until_class')->default(2); // sign-ups close when this class starts
            $table->boolean('makeups')->default(true);        // cancel in time → a make-up credit
            $table->boolean('allow_dropins')->default(false); // open spots bookable one class at a time
            $table->boolean('signups_open')->default(true);
            $table->timestamps();
            $table->index(['studio_id', 'starts_on']);
        });

        Schema::create('series_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10); // pending | active | withdrawn | released
            $table->unsignedSmallInteger('classes')->default(0); // classes it was for
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('source', 10); // web | staff
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['series_id', 'status']);
            $table->index(['attendee_id', 'status']);
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('program_id')->constrained('series')->nullOnDelete();
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('series_enrollment_id')->nullable()->after('entitlement_id')->constrained()->nullOnDelete();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('series_enrollment_id')->nullable()->after('entitlement_id')->constrained()->nullOnDelete();
        });
        Schema::table('entitlements', function (Blueprint $table) {
            $table->foreignId('series_enrollment_id')->nullable()->after('plan_id')->constrained()->nullOnDelete(); // a make-up credit
        });
    }

    public function down(): void
    {
        foreach (['entitlements', 'order_items', 'bookings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('series_enrollment_id'));
        }
        Schema::table('class_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('series_id'));
        Schema::dropIfExists('series_enrollments');
        Schema::dropIfExists('series');
    }
};
