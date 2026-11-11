<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo studios (decision 44): each "Try the demo" gets a private copy of a sample studio that is
 * deleted after a day. A few are built ahead (demo_claimed_at null) so starting one is instant.
 * The studio and every account made inside it carry the time they go; demo_studio_id isn't a
 * foreign key, so the accounts can still be found once the studio is gone. demo_runs counts
 * demos and which led to a real studio, with nothing about the visitor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->timestamp('demo_expires_at')->nullable()->index()->after('signup_source');
            $table->timestamp('demo_claimed_at')->nullable()->after('demo_expires_at'); // null: a spare, built ahead
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('demo_expires_at')->nullable()->index()->after('terms_version');
            $table->unsignedBigInteger('demo_studio_id')->nullable()->index()->after('demo_expires_at');
        });
        Schema::create('demo_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->nullable()->constrained()->nullOnDelete(); // the real studio started afterwards
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_runs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['demo_expires_at']);
            $table->dropIndex(['demo_studio_id']);
            $table->dropColumn(['demo_expires_at', 'demo_studio_id']);
        });
        Schema::table('studios', function (Blueprint $table) {
            $table->dropIndex(['demo_expires_at']);
            $table->dropColumn(['demo_expires_at', 'demo_claimed_at']);
        });
    }
};
