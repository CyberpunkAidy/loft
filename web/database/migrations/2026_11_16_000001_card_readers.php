<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Terminal card readers at the point of sale (roadmap step 6, last part).
 * - Each studio's readers are registered on its own Stripe account, at one Terminal Location
 *   (Stripe needs the studio's address for it).
 * - A sale charged on a reader is an in-person order (method "reader"), pending while the
 *   customer taps, with the reader it was sent to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_reader_id')->unique();
            $table->string('label', 80);
            $table->string('device_type', 40)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->timestamps();
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->string('stripe_terminal_location_id')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('card_reader_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_reader_id');
        });
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn('stripe_terminal_location_id');
        });
        Schema::dropIfExists('card_readers');
    }
};
