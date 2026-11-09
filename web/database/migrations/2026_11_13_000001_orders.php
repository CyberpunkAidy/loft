<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The orders ledger (roadmap step 5): one record of every sale, however it was paid. Online
 * checkouts, each paid membership invoice, and payments taken in person all become orders with
 * items, numbered per studio. Merchandise and the point of sale (step 6) add item kinds.
 *
 * Payments already recorded in person are copied in as orders. Earlier online sales aren't (their
 * amounts are in Stripe, not here): the ledger's online history starts with this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number'); // per studio, from 1001
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who bought; kept as a sale if they're deleted
            $table->string('channel', 15); // online | in_person
            $table->string('method', 20); // stripe | cash | check | card | transfer | app | other | comp
            $table->string('status', 15); // paid | void
            $table->char('currency', 3);
            $table->integer('subtotal_cents');
            $table->integer('discount_cents')->default(0);
            $table->integer('tax_cents')->default(0);
            $table->integer('total_cents');
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['studio_id', 'number']);
            $table->index(['studio_id', 'paid_at']);
            $table->index(['studio_id', 'user_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15); // plan | fee | other (products come with the point of sale)
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description'); // the name at the time of sale
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price_cents');
            $table->integer('total_cents');
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        });

        // Payments recorded in person become orders, numbered in the order they were taken.
        $numbers = [];
        $now = now();
        DB::table('payments')
            ->join('studios', 'studios.id', '=', 'payments.studio_id')
            ->leftJoin('plans', 'plans.id', '=', 'payments.plan_id')
            ->select('payments.*', 'studios.timezone', 'plans.name as plan_name')
            ->orderBy('payments.paid_on')->orderBy('payments.id')
            ->chunk(200, function ($payments) use (&$numbers, $now) {
                foreach ($payments as $p) {
                    $numbers[$p->studio_id] = ($numbers[$p->studio_id] ?? 1000) + 1;
                    $paidAt = Carbon::parse(substr((string) $p->paid_on, 0, 10).' 12:00:00', $p->timezone ?: 'UTC')->utc();
                    $orderId = DB::table('orders')->insertGetId([
                        'studio_id' => $p->studio_id,
                        'number' => $numbers[$p->studio_id],
                        'user_id' => $p->user_id,
                        'channel' => 'in_person',
                        'method' => $p->method,
                        'status' => $p->voided_at ? 'void' : 'paid',
                        'currency' => $p->currency,
                        'subtotal_cents' => $p->amount_cents,
                        'total_cents' => $p->amount_cents,
                        'recorded_by_user_id' => $p->recorded_by_user_id,
                        'paid_at' => $paidAt,
                        'voided_at' => $p->voided_at,
                        'voided_by_user_id' => $p->voided_by_user_id,
                        'created_at' => $p->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('order_items')->insert([
                        'order_id' => $orderId,
                        'kind' => 'plan',
                        'plan_id' => $p->plan_id,
                        'entitlement_id' => $p->entitlement_id,
                        'description' => $p->plan_name ?? 'Plan',
                        'quantity' => 1,
                        'unit_price_cents' => $p->amount_cents,
                        'total_cents' => $p->amount_cents,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('payments')->where('id', $p->id)->update(['order_id' => $orderId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
