<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff can record a member's photo answer (decision 46), say when a parent tells the front desk
 * "no photos of Leo". The note says how they heard; it's cleared when the member answers themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->string('photo_consent_note', 190)->nullable()->after('photo_consent_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn('photo_consent_note');
        });
    }
};
