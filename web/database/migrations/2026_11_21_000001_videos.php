<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-demand video (roadmap step 10), hosted on Loft's Mux account (decision 32).
 * - videos: a studio's library. status: uploading (waiting for the file) → processing (Mux is
 *   encoding it) → ready, or errored. Playback is signed, so the playback ID alone plays nothing.
 *   access: members (anyone with an account at the studio) or plan (a current plan that includes
 *   videos). Only published, ready videos are in the library.
 * - video_views: one row per person, video and day, for view counts (and the member timeline).
 * - plans.includes_videos: the plans that open "plan" videos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('access', 10)->default('members');
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('source', 10)->default('upload');
            $table->string('status', 12)->default('uploading');
            $table->string('error', 500)->nullable();
            $table->string('mux_upload_id', 100)->nullable()->index();
            $table->string('mux_asset_id', 100)->nullable()->index();
            $table->string('mux_playback_id', 100)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('aspect_ratio', 12)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['studio_id', 'published', 'status']);
        });

        Schema::create('video_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('viewed_on');
            $table->timestamp('created_at')->nullable();
            $table->unique(['video_id', 'user_id', 'viewed_on']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('includes_videos')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('includes_videos');
        });
        Schema::dropIfExists('video_views');
        Schema::dropIfExists('videos');
    }
};
