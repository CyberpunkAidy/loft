<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When people agreed to Loft's Terms of Service and Privacy Policy (decision 38): each account when
 * it was created (or, for an imported member, when they accepted their invitation), and each
 * studio, with the version of the terms its owner ticked at signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });
        Schema::table('studios', function (Blueprint $table) {
            $table->dateTime('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studios', fn (Blueprint $table) => $table->dropColumn(['terms_accepted_at', 'terms_version']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['terms_accepted_at', 'terms_version']));
    }
};
