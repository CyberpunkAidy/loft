<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchandise and the front-desk point of sale (roadmap step 6, first part):
 * - products, each with one or more variants (sizes, colours), each variant with its own stock;
 * - order items can be a product variant, with the sales tax on that line;
 * - orders can be for a walk-in (nobody's account) and carry a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->integer('price_cents');
            $table->boolean('taxable')->default(true); // the studio's sales tax rate applies
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60); // "Small", "Black / M", or "One size"
            $table->string('sku', 60)->nullable();
            $table->integer('price_cents')->nullable(); // null = the product's price
            $table->integer('stock')->nullable(); // null = not tracked
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('tax_cents')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('walk_in')->default(false); // sold to someone without an account
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['walk_in', 'note']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn('tax_cents');
        });
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
