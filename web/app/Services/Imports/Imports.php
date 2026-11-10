<?php

namespace App\Services\Imports;

use App\Jobs\RunImport;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\Import;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Support\CsvReader;
use App\Support\ImportException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data import from another system (decision 37). An upload becomes a draft; staff check how its
 * columns were matched and preview what it will do; importing runs on the queue (big files take a
 * while); a finished import can be undone for 30 days, which removes whatever it created that
 * nobody has used since.
 */
class Imports
{
    public function __construct(
        private readonly PeopleImporter $people,
        private readonly PlansImporter $plans,
    ) {}

    /** @throws ImportException */
    public function start(Studio $studio, ?int $userId, string $kind, string $fileName, string $content): Import
    {
        $table = CsvReader::parse($content);
        $content = CsvReader::toUtf8($content); // stored as text, so as UTF-8 whatever it came in
        $fileName = mb_check_encoding($fileName, 'UTF-8') ? $fileName : (string) mb_convert_encoding($fileName, 'UTF-8', 'Windows-1252');
        $mapping = Fields::match($kind, $table['headers']);
        $import = new Import([
            'studio_id' => $studio->id,
            'user_id' => $userId,
            'kind' => $kind,
            'file_name' => mb_substr($fileName, 0, 190),
            'csv' => $content,
            'row_count' => count($table['rows']),
            'mapping' => $mapping,
        ]);
        $import->options = $this->defaults($studio, $import, $table);
        $import->save();

        return $import;
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public function table(Import $import): array
    {
        return CsvReader::parse((string) $import->csv);
    }

    public function plan(Import $import, ?array $table = null): ImportPlan
    {
        $table ??= $this->table($import);
        $order = $import->options['date_order'] ?? 'mdy';

        return $import->kind === 'plans'
            ? $this->plans->plan($import->studio, $table['rows'], $import->mapping ?? [], $order, $import->options['plans'] ?? [])
            : $this->people->plan($import->studio, $table['rows'], $import->mapping ?? [], $order);
    }

    /**
     * Staff's choices on the preview page.
     *
     * @param  array<string, mixed>  $mapping  field => column index ('' for none)
     * @param  array<string, mixed>  $plans  normalized plan name => plan id (0 for don't import)
     */
    public function choose(Import $import, array $mapping, string $dateOrder, array $plans = []): void
    {
        $columns = count($this->table($import)['headers']);
        $clean = [];
        foreach (Fields::for($import->kind) as $field => $definition) {
            $value = $mapping[$field] ?? '';
            if ($value !== '' && $value !== null && ctype_digit((string) $value) && (int) $value < $columns) {
                $clean[$field] = (int) $value;
            }
        }
        $options = ['date_order' => array_key_exists($dateOrder, Dates::ORDERS) ? $dateOrder : 'mdy'];
        if ($import->kind === 'plans') {
            $planIds = $import->studio->plans()->pluck('id')->flip();
            $options['plans'] = collect($plans)->map(fn ($id) => isset($planIds[(int) $id]) ? (int) $id : 0)->all();
        }
        $import->update(['mapping' => $clean, 'options' => $options]);
    }

    /**
     * Why the import can't run yet, if it can't: a required column isn't matched.
     */
    public function problem(Import $import): ?string
    {
        $mapping = $import->mapping ?? [];
        if ($import->kind === 'plans') {
            return match (true) {
                ! isset($mapping['email']) => 'Choose the column with each member’s email.',
                ! isset($mapping['plan']) => 'Choose the column with the plan name.',
                default => null,
            };
        }
        if (! isset($mapping['first_name']) && ! isset($mapping['last_name']) && ! isset($mapping['full_name'])) {
            return 'Choose the columns with people’s names.';
        }
        if (! isset($mapping['email']) && ! isset($mapping['parent_email'])) {
            return 'Choose the column with people’s email addresses.';
        }

        return null;
    }

    public function queue(Import $import): void
    {
        $import->update(['status' => 'queued', 'error' => null]);
        RunImport::dispatch($import->id);
    }

    public function run(Import $import): void
    {
        if (! in_array($import->status, ['queued', 'failed'], true) || $import->csv === null) {
            return; // already run, or discarded
        }
        $import->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        try {
            $plan = $this->plan($import);
            $done = $import->kind === 'plans'
                ? $this->plans->apply($import->studio, $import, $plan)
                : $this->people->apply($import->studio, $import, $plan);
            $import->update([
                'status' => 'done',
                'finished_at' => now(),
                'summary' => $plan->counts + $done,
                'report' => $plan->report(),
                'csv' => null, // members' details stay only in what was imported
            ]);
        } catch (Throwable $e) {
            report($e);
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove what the import created, except anything used since: people with bookings, members
     * who accepted their invitation or hold plans, and credits or memberships used for a class.
     *
     * @return array{removed: int, kept: int}
     */
    public function undo(Import $import): array
    {
        $counts = ['removed' => 0, 'kept' => 0];
        if (! $import->canUndo()) {
            return $counts;
        }

        DB::transaction(function () use ($import, &$counts) {
            $settle = function (bool $inUse, callable $remove) use (&$counts) {
                if ($inUse) {
                    $counts['kept']++;
                } else {
                    $remove();
                    $counts['removed']++;
                }
            };
            if ($import->kind === 'plans') {
                foreach (Entitlement::where('import_id', $import->id)->get() as $entitlement) {
                    $continued = Entitlement::where('user_id', $entitlement->user_id)->where('plan_id', $entitlement->plan_id)
                        ->whereNotNull('stripe_subscription_id')->where('created_at', '>=', $entitlement->created_at)->exists();
                    $settle($continued || Booking::where('entitlement_id', $entitlement->id)->exists(), fn () => $entitlement->delete());
                }
            } else {
                foreach (Attendee::where('import_id', $import->id)->withCount(['bookings', 'signatures'])->get() as $attendee) {
                    $settle($attendee->bookings_count + $attendee->signatures_count > 0, fn () => $attendee->delete());
                }
                foreach (StudioUser::where('import_id', $import->id)->get() as $membership) {
                    $inUse = $membership->role !== 'member' || $membership->invitation_accepted_at
                        || Attendee::where('studio_id', $membership->studio_id)->where('user_id', $membership->user_id)->exists()
                        || Entitlement::where('studio_id', $membership->studio_id)->where('user_id', $membership->user_id)->exists()
                        || Booking::where('studio_id', $membership->studio_id)->where('user_id', $membership->user_id)->exists();
                    $settle((bool) $inUse, fn () => $membership->delete());
                }
                foreach (User::where('import_id', $import->id)->get() as $user) {
                    if (! $user->studioUsers()->exists() && ! $user->tokens()->exists()) {
                        $user->delete();
                    }
                }
            }
            $import->update(['status' => 'undone', 'undone_at' => now()]);
        });

        return $counts;
    }

    /** Hourly: drafts nobody imported, and failed imports, don't keep members' details for more than a week. */
    public function prune(): int
    {
        $old = Import::whereIn('status', ['draft', 'failed'])->where('updated_at', '<', now()->subDays(7));
        $count = (clone $old)->where('status', 'draft')->count();
        (clone $old)->where('status', 'draft')->delete();
        (clone $old)->where('status', 'failed')->whereNotNull('csv')->update(['csv' => null]);

        return $count;
    }

    /** @return array<string, mixed> date order, and for plans each plan name's match */
    private function defaults(Studio $studio, Import $import, array $table): array
    {
        $dateField = $import->kind === 'plans' ? 'expires' : 'dob';
        $dates = isset($import->mapping[$dateField]) ? array_column($table['rows'], $import->mapping[$dateField]) : [];
        $options = ['date_order' => Dates::detectOrder($dates, $studio->currency === 'usd' ? 'mdy' : 'dmy')];
        if ($import->kind === 'plans') {
            $options['plans'] = PlansImporter::matchPlans($studio, PlansImporter::planNames($table['rows'], $import->mapping));
        }

        return $options;
    }
}
