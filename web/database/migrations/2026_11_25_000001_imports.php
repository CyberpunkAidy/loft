<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import (decision 37): a studio moving from another system uploads CSV exports of its
 * members and families, then of their credits and memberships. Each upload is an `imports` row
 * (the file, how its columns map to Loft's fields, and what happened). Everything an import
 * creates points back at it (import_id), so it can be undone, its members invited when the owner
 * chooses, and imported people told apart from those who joined in Loft: an imported attendee's
 * first class in Loft isn't their first class, and imported members aren't scored as new joiners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who uploaded it
            $table->string('kind', 20); // people|plans
            $table->string('status', 20)->default('draft'); // draft|queued|running|done|failed|undone
            $table->string('file_name', 190);
            $table->longText('csv')->nullable(); // the upload, until it's imported or discarded
            $table->unsignedInteger('row_count')->default(0);
            $table->json('mapping')->nullable(); // Loft field => column index
            $table->json('options')->nullable(); // date order; plan name => plan id
            $table->json('summary')->nullable(); // counts of what it did
            $table->json('report')->nullable(); // rows skipped, with reasons
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();
            $table->index(['studio_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete(); // the import that created the account
        });
        Schema::table('studio_users', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('invitation_accepted_at')->nullable();
        });
        Schema::table('attendees', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('entitlements', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('member_snapshots', function (Blueprint $table) {
            $table->boolean('imported')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('member_snapshots', fn (Blueprint $table) => $table->dropColumn('imported'));
        Schema::table('entitlements', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::table('attendees', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::table('studio_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_id');
            $table->dropColumn(['invited_at', 'invitation_accepted_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::dropIfExists('imports');
    }
};
