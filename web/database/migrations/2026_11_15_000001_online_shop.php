<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The online shop (roadmap step 6, second part): members buy products online through Stripe
 * Checkout and pick them up at the studio.
 * - An order can wait for payment (status pending; its stock is held). It gets its number and
 *   paid_at when paid, so paid orders stay numbered without gaps.
 * - Orders to collect have a fulfillment status: to_pick_up, then picked_up.
 * - Each studio's sales tax rate is mirrored as a Stripe Tax Rate on its account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->change();
            $table->dateTime('paid_at')->nullable()->change();
            $table->string('fulfillment_status', 15)->nullable(); // to_pick_up | picked_up; null = handed over at the desk, or nothing to collect
            $table->dateTime('picked_up_at')->nullable();
            $table->foreignId('picked_up_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['studio_id', 'fulfillment_status']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->string('stripe_tax_rate_id')->nullable();
            $table->unsignedInteger('stripe_tax_rate_milli')->nullable(); // the rate that Tax Rate charges
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn(['stripe_tax_rate_id', 'stripe_tax_rate_milli']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['studio_id', 'fulfillment_status']);
            $table->dropConstrainedForeignId('picked_up_by_user_id');
            $table->dropColumn(['fulfillment_status', 'picked_up_at']);
        });
    }
};
