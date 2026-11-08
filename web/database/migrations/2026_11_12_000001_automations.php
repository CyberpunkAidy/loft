<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations (roadmap step 4): emails a studio sets up once and that then go out by themselves:
 * after someone's first class, on a birthday, and when a member stops coming. One of each per
 * studio. Each send is logged in message_deliveries, now with the automation that sent it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 40); // first_class | birthday | win_back
            $table->jsonb('conditions')->nullable(); // win_back: {"days": 30}
            $table->string('channel', 10)->default('email'); // sms and push later
            $table->unsignedInteger('delay_minutes')->default(0); // first_class: after the class
            $table->string('subject', 150);
            $table->text('body');
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['studio_id', 'trigger']);
        });

        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['automation_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropIndex(['automation_id', 'status', 'created_at']);
            $table->dropConstrainedForeignId('automation_id');
        });
        Schema::dropIfExists('automations');
    }
};
