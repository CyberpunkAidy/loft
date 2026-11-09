<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Churn scoring (roadmap step 9): each member's risk of leaving, scored nightly from that night's
 * snapshot. The model version and the reasons are kept with every score, so a trained model can
 * replace the rules later without anything downstream changing (automations read only this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->date('as_of');
            $table->string('model_version', 20);
            $table->unsignedTinyInteger('score'); // 0–100
            $table->string('level', 10); // low | medium | high | lapsed
            $table->json('reasons'); // [{code, points, label}]
            $table->dateTime('created_at');
            $table->unique(['studio_id', 'user_id', 'as_of', 'model_version']);
            $table->index(['studio_id', 'as_of', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_risk_scores');
    }
};
