<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral codes and how studios found Loft (decision 43). Every studio can have one code of its
 * own; partner codes (instructors and others who recommend Loft) have a name instead of a studio.
 * A studio records the code it joined with, the reward its referrer got once it first paid, its
 * answer to "How did you hear about Loft?", and where its first visit came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // upper case: SKYLINE
            $table->foreignId('studio_id')->nullable()->unique()->constrained()->cascadeOnDelete(); // null for partner codes
            $table->string('name', 120)->nullable(); // a partner code's owner, for the report
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->foreignId('referral_code_id')->nullable()->after('terms_version')->constrained()->nullOnDelete();
            $table->timestamp('referral_rewarded_at')->nullable()->after('referral_code_id');
            $table->string('referral_reward', 16)->nullable()->after('referral_rewarded_at'); // credit | trial | none | partner
            $table->unsignedInteger('referral_reward_cents')->nullable()->after('referral_reward');
            $table->string('heard_from', 24)->nullable()->after('referral_reward_cents');
            $table->string('heard_from_detail', 200)->nullable()->after('heard_from');
            $table->json('signup_source')->nullable()->after('heard_from_detail'); // utm_*, referrer host, landing page
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referral_code_id');
            $table->dropColumn(['referral_rewarded_at', 'referral_reward', 'referral_reward_cents', 'heard_from', 'heard_from_detail', 'signup_source']);
        });
        Schema::dropIfExists('referral_codes');
    }
};
