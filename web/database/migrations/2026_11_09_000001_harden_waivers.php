<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waivers you can stand behind (roadmap step 1):
 * - every published version's text is kept, unchanged, and each signature points at the version
 *   it signed, with a hash of the text;
 * - a waiver applies to every class, or only to the programs it's attached to, and can set its
 *   own validity;
 * - staff can let someone in without a signature, with a recorded reason;
 * - signatures outlive the accounts that made them (decision 20): deleting a user or attendee
 *   unlinks them instead of deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('title');
            $table->text('body');
            $table->char('body_sha256', 64);
            $table->timestamps();
            $table->unique(['waiver_id', 'version']);
        });

        Schema::table('waivers', function (Blueprint $table) {
            $table->string('scope', 20)->default('studio'); // studio = every class; program = attached programs only
            $table->unsignedSmallInteger('validity_days')->nullable(); // null = the studio's setting
        });

        Schema::create('program_waiver', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waiver_id')->constrained()->cascadeOnDelete();
            $table->primary(['program_id', 'waiver_id']);
        });

        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->foreignId('waiver_version_id')->nullable()->constrained('waiver_versions')->nullOnDelete();
            $table->char('body_sha256', 64)->nullable(); // the exact text signed; null for signatures from before this
            $table->string('attendee_name')->nullable();
            $table->string('signer_relationship', 20)->nullable(); // self | guardian | account_holder
        });

        // Unlink instead of delete when the attendee or the signing account goes away.
        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->dropForeign(['attendee_id']);
            $table->dropForeign(['signed_by_user_id']);
        });
        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->unsignedBigInteger('attendee_id')->nullable()->change();
            $table->unsignedBigInteger('signed_by_user_id')->nullable()->change();
            $table->foreign('attendee_id')->references('id')->on('attendees')->nullOnDelete();
            $table->foreign('signed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('waiver_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamps();
        });

        // Backfill: each waiver's current text becomes its current version. Older versions' text
        // wasn't kept, so signatures of those keep only their version number.
        foreach (DB::table('waivers')->orderBy('id')->get() as $waiver) {
            $versionId = DB::table('waiver_versions')->insertGetId([
                'studio_id' => $waiver->studio_id,
                'waiver_id' => $waiver->id,
                'version' => $waiver->version,
                'title' => $waiver->title,
                'body' => $waiver->body,
                'body_sha256' => hash('sha256', $waiver->body),
                'created_at' => $waiver->updated_at,
                'updated_at' => $waiver->updated_at,
            ]);
            DB::table('waiver_signatures')->where('waiver_id', $waiver->id)->where('waiver_version', $waiver->version)
                ->update(['waiver_version_id' => $versionId]);
        }
        foreach (DB::table('waiver_signatures')->join('attendees', 'attendees.id', '=', 'waiver_signatures.attendee_id')
            ->select('waiver_signatures.id', 'attendees.first_name', 'attendees.last_name', 'attendees.is_self')->get() as $row) {
            DB::table('waiver_signatures')->where('id', $row->id)->update([
                'attendee_name' => trim($row->first_name.' '.$row->last_name),
                'signer_relationship' => $row->is_self ? 'self' : 'account_holder', // the account holder signed for them
            ]);
        }

        // Several waivers can now be in force at once, so no single "active" one.
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_overrides');
        Schema::table('waiver_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waiver_version_id');
            $table->dropColumn(['body_sha256', 'attendee_name', 'signer_relationship']);
        });
        Schema::dropIfExists('program_waiver');
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropColumn(['scope', 'validity_days']);
        });
        Schema::dropIfExists('waiver_versions');
    }
};
