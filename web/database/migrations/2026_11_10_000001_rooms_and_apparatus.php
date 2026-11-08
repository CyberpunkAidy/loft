<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms, apparatus capacity and waitlist limits (roadmap step 2, decision 18):
 * - a studio has rooms, each with a fire-code limit;
 * - apparatus is rigged in a room ("Silks: 5, up to 2 people each");
 * - a class uses some of its room's apparatus: the program holds the default, each session can change it;
 * - a booking takes a spot on one apparatus, and can record which item ("Silks 3");
 * - a session can cap its waitlist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('max_capacity'); // fire-code limit
            $table->timestamps();
            $table->unique(['studio_id', 'name']);
        });

        Schema::create('apparatus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60); // Silks, Lyra, Trapeze, Pole
            $table->unsignedSmallInteger('quantity'); // how many are rigged
            $table->unsignedTinyInteger('max_per_item'); // the rig's limit: people per item
            $table->timestamps();
            $table->unique(['room_id', 'name']);
        });

        Schema::create('program_apparatus', function (Blueprint $table) { // a program's default allocation
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('apparatus_id')->constrained('apparatus')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity'); // at most the room's
            $table->unsignedTinyInteger('max_per_item'); // at most the rig's
            $table->unique(['program_id', 'apparatus_id']);
        });

        Schema::create('session_apparatus', function (Blueprint $table) { // what this session uses
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('apparatus_id')->constrained('apparatus')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedTinyInteger('max_per_item');
            $table->unique(['class_session_id', 'apparatus_id']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('waitlist_capacity')->nullable(); // default for new sessions; null = no limit
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('waitlist_capacity')->nullable(); // null = no limit, 0 = no waitlist
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('apparatus_id')->nullable()->constrained('apparatus')->nullOnDelete();
            $table->unsignedSmallInteger('apparatus_item')->nullable(); // "Silks 3", for the roster
            $table->index(['class_session_id', 'apparatus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['class_session_id', 'apparatus_id', 'status']);
            $table->dropConstrainedForeignId('apparatus_id');
            $table->dropColumn('apparatus_item');
        });
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn('waitlist_capacity');
        });
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn('waitlist_capacity');
        });
        Schema::dropIfExists('session_apparatus');
        Schema::dropIfExists('program_apparatus');
        Schema::dropIfExists('apparatus');
        Schema::dropIfExists('rooms');
    }
};
