<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sent_notifications kept reminder and credit-expiry emails from going out twice until the event
 * backbone (2026_11_11) replaced it with message_deliveries and copied its rows there. Nothing has
 * written or read it since, so it goes. Rolling back brings the table back empty; its rows live on
 * in message_deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sent_notifications');
    }

    public function down(): void
    {
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->unsignedBigInteger('ref_id');
            $table->dateTime('sent_at');
            $table->unique(['kind', 'ref_id']);
        });
    }
};
