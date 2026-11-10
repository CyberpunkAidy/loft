<?php

namespace App\Services\Imports;

use App\Models\Entitlement;
use App\Models\Import;
use App\Models\Plan;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\DomainEvents;
use App\Support\CsvReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Credits and memberships members already paid for in another system (decision 37), each matched
 * to one of the studio's plans by name. A pack comes in with the classes it has left and its
 * expiry. A membership comes in active until the date it's paid through, with no card behind it:
 * before then the member is asked to start it in Loft, where their card is first charged when it
 * ends (MemberInvitations::remindRenewals, PlanCheckout). Members are found by email, so import
 * members first. Nothing here is a sale: no orders are recorded.
 */
final class PlansImporter
{
    public function __construct(private readonly DomainEvents $events) {}

    /**
     * The plan names in the file, with how many rows use each: normalized name => [name, count].
     *
     * @param  list<list<string>>  $rows
     * @return array<string, array{name: string, count: int}>
     */
    public static function planNames(array $rows, array $mapping): array
    {
        $names = [];
        if (! isset($mapping['plan'])) {
            return $names;
        }
        foreach ($rows as $row) {
            $name = trim((string) ($row[$mapping['plan']] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = CsvReader::normalize($name);
            $names[$key] ??= ['name' => $name, 'count' => 0];
            $names[$key]['count']++;
        }

        return $names;
    }

    /**
     * Each name matched to the studio's plan with the same name, if there is one.
     *
     * @param  array<string, array{name: string, count: int}>  $names
     * @return array<string, int> normalized name => plan id (0: don't import)
     */
    public static function matchPlans(Studio $studio, array $names): array
    {
        $plans = $studio->plans()->whereIn('kind', ['subscription', 'season', 'pack', 'dropin'])->get()
            ->keyBy(fn (Plan $p) => CsvReader::normalize($p->name));

        return collect($names)->map(fn ($name, $key) => (int) ($plans->get($key)?->id ?? 0))->all();
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $mapping
     * @param  array<string, int>  $choices  normalized plan name => plan id (0: don't import)
     */
    public function plan(Studio $studio, array $rows, array $mapping, string $dateOrder, array $choices): ImportPlan
    {
        $plan = new ImportPlan;
        $get = fn (array $row, string $field) => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $plans = $studio->plans()->get()->keyBy('id');

        $rows = collect($rows)->map(fn ($row, $index) => [
            'line' => $index + 2,
            'email' => PeopleImporter::email($get($row, 'email')),
            'email_text' => $get($row, 'email'),
            'name' => $get($row, 'full_name'),
            'plan' => $get($row, 'plan'),
            'credits' => $get($row, 'credits_left'),
            'expires' => $get($row, 'expires'),
        ]);
        $members = self::members($studio, $rows->pluck('email')->filter()->unique()->values()->all());
        $imported = Entitlement::where('studio_id', $studio->id)->whereNotNull('import_id')->whereIn('user_id', $members->pluck('id'))
            ->get(['user_id', 'plan_id', 'credits_remaining', 'expires_at'])
            ->map(fn (Entitlement $e) => self::itemKey($e->user_id, $e->plan_id, $e->credits_remaining, $e->expires_at?->toDateString()))
            ->flip();
        $inFile = [];

        foreach ($rows as $row) {
            $name = $row['name'] !== '' ? $row['name'] : $row['email_text'];
            if (! $row['email']) {
                $plan->skip($row['line'], $name, $row['email_text'] !== '' ? "“{$row['email_text']}” isn’t an email address" : 'No email');

                continue;
            }
            $member = $members->get($row['email']);
            if (! $member) {
                $plan->skip($row['line'], $name, "No member with {$row['email']}. Import your members first");

                continue;
            }
            $name = $row['name'] !== '' ? $row['name'] : $member->name;
            if ($row['plan'] === '') {
                $plan->skip($row['line'], $name, 'No plan');

                continue;
            }
            $loftPlan = $plans->get($choices[CsvReader::normalize($row['plan'])] ?? 0);
            if (! $loftPlan) {
                $plan->skip($row['line'], $name, "“{$row['plan']}” isn’t matched to one of your plans");

                continue;
            }

            $expiresText = $row['expires'];
            $date = Dates::parse($expiresText, $dateOrder);
            if ($expiresText !== '' && ! $date) {
                $plan->skip($row['line'], $name, "The date “{$expiresText}” wasn’t understood");

                continue;
            }
            // The member's last day, in the studio's time zone.
            $endsAt = $date ? $studio->parseLocal($date->toDateString().' 23:59:59')->utc() : null;
            if ($endsAt && $endsAt->isPast()) {
                $plan->skip($row['line'], $name, ($loftPlan->isRecurring() ? 'Paid through ' : 'Expired on ').$studio->local($endsAt)->format('M j, Y'));

                continue;
            }

            if ($loftPlan->isRecurring()) {
                if (! $endsAt) {
                    $plan->skip($row['line'], $name, 'No paid-through date, so there’s no knowing when it ends');

                    continue;
                }
                $credits = null;
            } else {
                $credits = self::number($row['credits']);
                if ($credits === null) {
                    $plan->skip($row['line'], $name, 'No number of classes left');

                    continue;
                }
                if ($credits <= 0) {
                    $plan->skip($row['line'], $name, 'No classes left');

                    continue;
                }
                if (! $endsAt) {
                    $validity = $loftPlan->credit_validity_days ?? (int) $studio->setting('credit_validity_days');
                    $endsAt = $validity > 0 ? now()->addDays($validity) : null;
                }
            }

            $key = self::itemKey($member->id, $loftPlan->id, $credits, $endsAt?->toDateString());
            if (isset($imported[$key]) || isset($inFile[$key])) {
                $plan->skip($row['line'], $name, isset($imported[$key]) ? 'Already imported' : 'Listed twice');

                continue;
            }
            $inFile[$key] = true;

            $plan->items[] = [
                'line' => $row['line'],
                'name' => $name,
                'user_id' => $member->id,
                'plan_id' => $loftPlan->id,
                'plan_name' => $loftPlan->name,
                'kind' => $loftPlan->isRecurring() ? 'subscription' : 'credits',
                'credits' => $credits,
                'expires_at' => $endsAt?->toIso8601String(),
            ];
        }

        $items = collect($plan->items);
        $plan->counts = [
            'packs' => $items->where('kind', 'credits')->count(),
            'memberships' => $items->where('kind', 'subscription')->count(),
            'skipped' => count($plan->skipped),
        ];

        return $plan;
    }

    /** @return array<string, int> */
    public function apply(Studio $studio, Import $import, ImportPlan $plan): array
    {
        $plans = $studio->plans()->get()->keyBy('id');
        $added = ['added_packs' => 0, 'added_memberships' => 0];

        foreach ($plan->items as $item) {
            $loftPlan = $plans->get($item['plan_id']);
            if (! $loftPlan) {
                continue; // deleted since the preview
            }
            DB::transaction(function () use ($studio, $import, $item, $loftPlan, &$added) {
                $expiresAt = $item['expires_at'] ? CarbonImmutable::parse($item['expires_at']) : null;
                $entitlement = Entitlement::create([
                    'studio_id' => $studio->id,
                    'user_id' => $item['user_id'],
                    'plan_id' => $loftPlan->id,
                    'kind' => $item['kind'],
                    'status' => 'active',
                    'credits_total' => $item['credits'] !== null ? max((int) $loftPlan->credits, $item['credits']) : null,
                    'credits_remaining' => $item['credits'],
                    'expires_at' => $expiresAt,
                    'current_period_end' => $item['kind'] === 'subscription' ? $expiresAt : null,
                    'program_scope' => $loftPlan->program_scope,
                    'weekly_limit' => $loftPlan->weekly_limit,
                    'import_id' => $import->id,
                ]);
                $entitlement->setRelation('plan', $loftPlan);
                $this->events->forEntitlement('entitlement.granted', $entitlement, ['source' => 'import']);
                $added[$item['kind'] === 'subscription' ? 'added_memberships' : 'added_packs']++;
            });
        }

        return $added;
    }

    /** The first whole number in "6", "6 of 10", "6.0". */
    public static function number(string $value): ?int
    {
        return preg_match('/-?\d+/', $value, $m) ? (int) $m[0] : null;
    }

    /** @param list<string> $emails */
    private static function members(Studio $studio, array $emails): Collection
    {
        $members = collect();
        foreach (array_chunk($emails, 500) as $chunk) {
            $members = $members->merge(User::whereIn('email', $chunk)
                ->whereIn('id', StudioUser::where('studio_id', $studio->id)->select('user_id'))
                ->get(['id', 'email', 'first_name', 'last_name']));
        }

        return $members->keyBy('email');
    }

    private static function itemKey(int $userId, ?int $planId, ?int $credits, ?string $date): string
    {
        return "{$userId}|{$planId}|".($credits ?? '-').'|'.substr((string) $date, 0, 10);
    }
}
