<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live virtual classes (roadmap step 10): a class is in the studio, online, or both (hybrid).
 * - delivery: in_person | online | hybrid. Online classes use their capacity for online spots;
 *   hybrid ones keep capacity for the studio and have online_capacity spots online (null = no limit).
 * - meeting_url: the studio's Zoom, Meet or other link, shown to people booked online shortly
 *   before class. Programs carry defaults for the sessions they generate.
 * - bookings.online_spot: someone joining a hybrid class online. Those don't count against the
 *   studio's seats, waitlist or apparatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['programs', 'class_sessions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('delivery', 10)->default('in_person');
                $t->unsignedSmallInteger('online_capacity')->nullable();
                $t->string('meeting_url', 500)->nullable();
            });
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('online_spot')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('online_spot');
        });
        foreach (['programs', 'class_sessions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['delivery', 'online_capacity', 'meeting_url']);
            });
        }
    }
};
