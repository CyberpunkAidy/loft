<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio logos (decision 41). The image lives in its own table (base64, a few tens of KB after
 * StudioLogos re-encodes it) so loading a studio never loads it; the studio row keeps what pages
 * need to show it: a content hash for the URL, its type and its size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_logos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('data'); // base64 of the PNG or JPEG served at /{studio}/logo/{hash}.{type}
            $table->timestamps();
        });

        Schema::table('studios', function (Blueprint $table) {
            $table->char('logo_hash', 12)->nullable()->after('accent_color');
            $table->string('logo_type', 4)->nullable()->after('logo_hash'); // png | jpg
            $table->unsignedSmallInteger('logo_width')->nullable()->after('logo_type');
            $table->unsignedSmallInteger('logo_height')->nullable()->after('logo_width');
        });
    }

    public function down(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->dropColumn(['logo_hash', 'logo_type', 'logo_width', 'logo_height']);
        });
        Schema::dropIfExists('studio_logos');
    }
};
