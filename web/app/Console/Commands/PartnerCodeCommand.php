<?php

namespace App\Console\Commands;

use App\Models\ReferralCode;
use App\Services\Referrals;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Referral codes for people who aren't studios (decision 43): instructors, partners, an event.
 * Studios that join with one get the same extra trial as with a studio's code; partners are
 * rewarded by hand (loft:signups shows who each one brought in). Run it in Render's shell:
 *
 *   php artisan loft:partner-code MAYA --name="Maya (aerial instructor)"   make a code
 *   php artisan loft:partner-code MAYA --disable                           stop it working
 *   php artisan loft:partner-code MAYA --enable                            start it again
 *   php artisan loft:partner-code                                          list partner codes
 */
#[AsCommand(name: 'loft:partner-code', description: 'Make, list, turn off or turn on referral codes for partners')]
class PartnerCodeCommand extends Command
{
    protected $signature = 'loft:partner-code
        {code? : The code: 3 to 20 letters and numbers, like MAYA}
        {--name= : Who it’s for, for your records}
        {--disable : Stop the code working (any code, a studio’s too)}
        {--enable : Let a turned-off code work again}';

    public function handle(): int
    {
        if ($this->argument('code') === null) {
            return $this->list();
        }
        $code = Referrals::normalize((string) $this->argument('code'));
        if (strlen($code) < 3 || strlen($code) > 20 || strtoupper((string) $this->argument('code')) !== $code) {
            $this->error('Use 3 to 20 letters and numbers, like MAYA.');

            return self::FAILURE;
        }
        $existing = ReferralCode::with('studio')->where('code', $code)->first();

        if ($this->option('disable') || $this->option('enable')) {
            if (! $existing) {
                $this->error("There’s no code {$code}.");

                return self::FAILURE;
            }
            $existing->update(['disabled_at' => $this->option('disable') ? now() : null]);
            $this->info("{$code} ({$existing->owner()}) is ".($this->option('disable') ? 'turned off.' : 'working again.'));

            return self::SUCCESS;
        }

        if ($existing) {
            $this->error("{$code} is already ".($existing->studio ? "{$existing->studio->name}’s code." : "a partner code ({$existing->owner()})."));

            return self::FAILURE;
        }
        $created = ReferralCode::create(['code' => $code, 'name' => $this->option('name') ?: null]);
        $this->info("Made {$code}".($created->name ? " for {$created->name}" : '').'. Share it as '.$created->url().' or have them enter the code when they sign up.');

        return self::SUCCESS;
    }

    private function list(): int
    {
        $codes = ReferralCode::whereNull('studio_id')->withCount('referredStudios')->orderBy('code')->get();
        if ($codes->isEmpty()) {
            $this->line('No partner codes yet. Make one: php artisan loft:partner-code MAYA --name="Maya"');

            return self::SUCCESS;
        }
        $this->table(['Code', 'For', 'Studios', 'Working'], $codes->map(fn (ReferralCode $c) => [
            $c->code, $c->name ?? '', $c->referred_studios_count, $c->disabled_at ? 'no' : 'yes',
        ])->all());

        return self::SUCCESS;
    }
}
