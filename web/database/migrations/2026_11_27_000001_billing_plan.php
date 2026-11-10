<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan a studio's Loft subscription is on (decision 40): billed monthly or yearly, and the
 * amount Stripe charges for it. Kept so staff see the price they're locked at, which can differ
 * from today's price on the pricing page. Filled in from Stripe each time the subscription syncs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->string('billing_interval', 5)->nullable()->after('billing_status'); // month | year
            $table->unsignedInteger('billing_amount_cents')->nullable()->after('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn(['billing_interval', 'billing_amount_cents']);
        });
    }
};
