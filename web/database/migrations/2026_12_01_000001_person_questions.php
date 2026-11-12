<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The injury and photo questions (decision 45). Each person on an account answers both, once, before
 * their first booking (or when they're added): any injuries or health conditions (the details stay in
 * medical_notes, and health_answered_at says the question was answered, so "no" is an answer too), and
 * whether the studio may photograph or film them. Nobody has answered yet, so everyone is asked at
 * their next booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->timestamp('health_answered_at')->nullable()->after('medical_notes');
            $table->string('photo_consent', 10)->nullable()->after('health_answered_at'); // public | studio | none
            $table->timestamp('photo_consent_at')->nullable()->after('photo_consent');
            $table->foreignId('photo_consent_by')->nullable()->after('photo_consent_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photo_consent_by');
            $table->dropColumn(['health_answered_at', 'photo_consent', 'photo_consent_at']);
        });
    }
};
