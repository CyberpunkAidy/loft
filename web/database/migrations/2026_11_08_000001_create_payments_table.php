<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments staff record by hand: cash, checks, card terminals, transfers, payment apps, and comps
 * (no charge). Online payments live in each studio's Stripe account instead. A record outlives the
 * member's account (user_id goes null) so the studio's books stay whole; it goes with the studio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the member
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete(); // what it granted
            $table->string('method', 20); // comp|cash|check|card|transfer|app|other
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['studio_id', 'paid_on']);
            $table->index(['studio_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
