<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds in the orders ledger (decision 34). refunded_cents is Stripe's running total of money given
 * back for the sale, from Loft or the Stripe Dashboard. A full refund sets the status to "refunded"
 * (what it paid for is taken back); a partial one leaves it "paid" and is recorded only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by_user_id');
            $table->dropColumn(['refunded_cents', 'refunded_at']);
        });
    }
};
