<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loft's own subscription (decision 36): each studio pays Loft monthly, on Loft's Stripe account.
 * A free trial from signup, then a subscription; or comped (free). The billing_* columns mirror
 * the studio's Stripe subscription on the platform account. Studios that exist when this runs are
 * comped, so nobody already using Loft is asked to pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('comped_at')->nullable();
            $table->string('comp_note', 200)->nullable();
            $table->string('billing_customer_id', 64)->nullable()->unique();
            $table->string('billing_subscription_id', 64)->nullable()->unique();
            $table->string('billing_status', 32)->nullable();
            $table->dateTime('billing_period_ends_at')->nullable();
            $table->dateTime('billing_cancels_at')->nullable();
            $table->dateTime('billing_payment_failed_at')->nullable();
            $table->dateTime('billing_ended_at')->nullable();
        });

        DB::table('studios')->update([
            'comped_at' => now(),
            'comp_note' => 'Already on Loft when subscriptions began',
        ]);
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropUnique(['billing_customer_id']);
            $table->dropUnique(['billing_subscription_id']);
            $table->dropColumn([
                'trial_ends_at', 'comped_at', 'comp_note', 'billing_customer_id', 'billing_subscription_id', 'billing_status',
                'billing_period_ends_at', 'billing_cancels_at', 'billing_payment_failed_at', 'billing_ended_at',
            ]);
        });
    }
};
