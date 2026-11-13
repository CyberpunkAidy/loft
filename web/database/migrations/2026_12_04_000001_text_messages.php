<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Text messages (decision 48): each studio's own Twilio account and number, members' consent per
 * studio (booking texts and marketing texts kept apart, as the TCPA treats them), a normalized
 * phone number to match replies, and a text version of each automation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studios', function (Blueprint $table) {
            $table->string('twilio_account_sid', 64)->nullable();
            $table->text('twilio_auth_token')->nullable(); // encrypted (APP_KEY)
            $table->string('sms_from', 20)->nullable(); // the number texts come from, E.164
            $table->string('sms_number_sid', 64)->nullable(); // Twilio's PN… id for it
            $table->dateTime('sms_connected_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_e164', 20)->nullable()->index(); // `phone` as +13035550123, null if it isn't a number
        });

        Schema::table('studio_users', function (Blueprint $table) {
            $table->dateTime('sms_updates_at')->nullable(); // agreed to texts about their bookings
            $table->string('sms_updates_source', 20)->nullable(); // join | profile | app | reply_start
            $table->dateTime('sms_marketing_at')->nullable(); // agreed to news and offers by text
            $table->string('sms_marketing_source', 20)->nullable();
            $table->dateTime('sms_stopped_at')->nullable(); // replied STOP: the carrier blocks texts until START
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->text('sms_body')->nullable(); // the text version; channel says email, sms or both
        });

        DB::table('users')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                if ($e164 = Phone::e164($user->phone)) {
                    DB::table('users')->where('id', $user->id)->update(['phone_e164' => $e164]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('automations', fn (Blueprint $table) => $table->dropColumn('sms_body'));
        Schema::table('studio_users', fn (Blueprint $table) => $table->dropColumn(['sms_updates_at', 'sms_updates_source', 'sms_marketing_at', 'sms_marketing_source', 'sms_stopped_at']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone_e164']);
            $table->dropColumn('phone_e164');
        });
        Schema::table('studios', fn (Blueprint $table) => $table->dropColumn(['twilio_account_sid', 'twilio_auth_token', 'sms_from', 'sms_number_sid', 'sms_connected_at']));
    }
};
