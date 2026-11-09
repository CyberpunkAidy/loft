<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactless check-in (roadmap step 8): QR codes and NFC/RFID tags first.
 * - access_devices: kiosks and readers, each with its own token (stored hashed). Not user accounts.
 * - access_credentials: a tag (card, fob, sticker) given to someone on a member's account; its UID
 *   is stored as a keyed hash. QR codes are signed and rotate, so they aren't stored.
 * - access_events: every scan, allowed or denied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('token_hash', 64)->unique();
            $table->string('token_last4', 4);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('access_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('tag');
            $table->char('value_hash', 64);
            $table->string('value_last4', 8);
            $table->string('label', 60)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['studio_id', 'value_hash']);
        });

        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('access_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10); // qr | tag
            $table->string('result', 10); // granted | denied
            $table->string('reason', 30)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->index(['studio_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
        Schema::dropIfExists('access_credentials');
        Schema::dropIfExists('access_devices');
    }
};
