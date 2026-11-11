<?php

namespace App\Services\Imports;

use App\Models\Attendee;
use App\Models\Import;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Consent;
use App\Services\DomainEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Members and their families from another system's client export (decision 37).
 *
 * Rows are grouped into accounts by email: a row's own email, or its parent's email when it has
 * one. That covers both ways systems record children: as their own rows sharing a parent's email,
 * or with a parent's-email column. In each account the first adult row is the account holder
 * (who also takes classes); everyone else is on their family. Accounts are global in Loft, so an
 * email that already has a Loft account joins this studio as it is: the import never changes an
 * existing person's details.
 */
final class PeopleImporter
{
    public function __construct(
        private readonly DomainEvents $events,
        private readonly Consent $consent,
    ) {}

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $mapping  field => column index
     */
    public function plan(Studio $studio, array $rows, array $mapping, string $dateOrder): ImportPlan
    {
        $plan = new ImportPlan;
        $get = fn (array $row, string $field) => isset($mapping[$field]) ? trim((string) ($row[$mapping[$field]] ?? '')) : '';
        $minorAge = (int) $studio->setting('minor_age');

        /** @var array<string, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2; // the header is line 1
            [$first, $last] = self::names($get($row, 'first_name'), $get($row, 'last_name'), $get($row, 'full_name'));
            $name = trim("{$first} {$last}");
            if ($name === '') {
                $plan->skip($line, '', 'No name');

                continue;
            }
            $emailText = $get($row, 'email');
            $parentText = $get($row, 'parent_email');
            $email = self::email($emailText);
            $parentEmail = self::email($parentText);
            $key = $parentEmail ?? $email;
            if (! $key) {
                $bad = $parentText !== '' ? $parentText : $emailText;
                $plan->skip($line, $name, $bad !== '' ? "“{$bad}” isn’t an email address" : 'No email or parent’s email');

                continue;
            }

            $dobText = $get($row, 'dob');
            $dob = Dates::parse($dobText, $dateOrder);
            if ($dobText !== '' && (! $dob || $dob->isFuture())) {
                $plan->warn($line, $name, "Birthday “{$dobText}” wasn’t understood, so it was left blank");
                $dob = null;
            }

            $groups[$key][] = [
                'line' => $line,
                'first_name' => Str::limit($first, 100, ''),
                'last_name' => Str::limit($last, 100, ''),
                'dob' => $dob?->toDateString(),
                'minor' => $dob !== null && $dob->age < $minorAge,
                'dependent' => $parentEmail !== null && $parentEmail !== $email,
                'phone' => Str::limit($get($row, 'phone'), 40, '') ?: null,
                'medical_notes' => Str::limit($get($row, 'medical_notes'), 2000, '') ?: null,
                'emergency_contact' => Str::limit(implode(', ', array_filter([$get($row, 'emergency_name'), $get($row, 'emergency_phone')])), 255, '') ?: null,
                'marketing' => self::yes($get($row, 'marketing')),
                'parent_name' => $get($row, 'parent_name'),
            ];
        }

        $users = self::usersByEmail(array_keys($groups));
        $memberIds = StudioUser::where('studio_id', $studio->id)->whereIn('user_id', $users->pluck('id'))->pluck('user_id')->flip();
        $existing = Attendee::where('studio_id', $studio->id)->whereIn('user_id', $users->pluck('id'))->where('status', 'active')
            ->get(['user_id', 'first_name', 'last_name', 'is_self'])->groupBy('user_id');

        foreach ($groups as $email => $people) {
            $user = $users->get($email);
            $holder = collect($people)->first(fn ($p) => ! $p['dependent'] && ! $p['minor']);
            $account = [
                'email' => $email,
                'user_id' => $user?->id,
                'status' => ! $user ? 'new' : (isset($memberIds[$user->id]) ? 'member' : 'existing'),
                'marketing' => collect($people)->contains(fn ($p) => ! $p['dependent'] && $p['marketing']),
                'phone' => $holder['phone'] ?? (collect($people)->firstWhere('dependent', false) ?? [])['phone'] ?? null,
                'people' => [],
            ];

            if ($user) {
                [$account['first_name'], $account['last_name']] = [$user->first_name, $user->last_name];
            } elseif ($holder) {
                [$account['first_name'], $account['last_name']] = [$holder['first_name'], $holder['last_name']];
            } elseif ($parentName = collect($people)->pluck('parent_name')->first(fn ($n) => $n !== '')) {
                [$account['first_name'], $account['last_name']] = self::names('', '', $parentName);
            } else {
                // Only children on this email, and no parent's name to go on.
                $account['first_name'] = $people[0]['last_name'] !== '' ? $people[0]['last_name'] : $people[0]['first_name'];
                $account['last_name'] = 'family';
                $plan->warn($people[0]['line'], trim("{$people[0]['first_name']} {$people[0]['last_name']}"), "No adult with {$email}, so the account is named “{$account['first_name']} family” until they change it");
            }

            $seen = $existing->get($user?->id ?? 0, collect())
                ->map(fn ($a) => self::personKey($a->first_name, $a->last_name))->flip()->all();
            foreach ($people as $person) {
                $personName = trim("{$person['first_name']} {$person['last_name']}");
                $isSelf = $person === $holder; // unless they have themselves already (apply)
                $key = self::personKey($person['first_name'], $person['last_name']);
                if (isset($seen[$key])) {
                    $plan->skip($person['line'], $personName, is_int($seen[$key]) ? 'Already in Loft' : 'Listed twice');

                    continue;
                }
                $seen[$key] = 'file';
                $account['people'][] = [
                    'line' => $person['line'],
                    'first_name' => $person['first_name'],
                    'last_name' => $person['last_name'],
                    'dob' => $person['dob'],
                    'is_self' => $isSelf,
                    'medical_notes' => $person['medical_notes'],
                    'emergency_contact' => $person['emergency_contact'],
                ];
            }
            $plan->items[] = $account;
        }

        $accounts = collect($plan->items);
        $plan->counts = [
            'accounts_new' => $accounts->where('status', 'new')->count(),
            'accounts_existing' => $accounts->where('status', 'existing')->count(),
            'accounts_member' => $accounts->where('status', 'member')->count(),
            'people' => $accounts->sum(fn ($a) => count($a['people'])),
            'family' => $accounts->sum(fn ($a) => collect($a['people'])->where('is_self', false)->count()),
            'skipped' => count($plan->skipped),
        ];

        return $plan;
    }

    /**
     * Create what the plan says, one account at a time (each in its own transaction, so a
     * failure part-way leaves whole accounts, and running the file again picks up the rest).
     *
     * @return array<string, int>
     */
    public function apply(Studio $studio, Import $import, ImportPlan $plan): array
    {
        $done = ['created_accounts' => 0, 'added_members' => 0, 'added_people' => 0];

        foreach ($plan->items as $account) {
            DB::transaction(function () use ($studio, $import, $account, &$done) {
                $user = User::where('email', $account['email'])->first();
                if (! $user) {
                    $user = User::create([
                        'first_name' => $account['first_name'],
                        'last_name' => $account['last_name'],
                        'email' => $account['email'],
                        'phone' => $account['phone'],
                        'password' => Str::random(40), // replaced when they accept the invitation
                        'import_id' => $import->id,
                    ] + User::madeIn($studio));
                    $done['created_accounts']++;
                }

                $membership = StudioUser::firstOrNew(['studio_id' => $studio->id, 'user_id' => $user->id]);
                if (! $membership->exists) {
                    $membership->fill(['role' => 'member', 'import_id' => $import->id])->save();
                    $this->events->record('member.joined', $studio, ['source' => 'import'], $user->id);
                    $done['added_members']++;
                }
                if ($account['marketing'] && ! $membership->email_marketing_consent_at && ! $membership->email_unsubscribed_at) {
                    $this->consent->setMarketingEmail($membership, true, 'import');
                }

                $existing = Attendee::where('studio_id', $studio->id)->where('user_id', $user->id)->where('status', 'active')->get();
                foreach ($account['people'] as $person) {
                    if ($existing->contains(fn ($a) => self::personKey($a->first_name, $a->last_name) === self::personKey($person['first_name'], $person['last_name']))) {
                        continue; // added since the preview
                    }
                    Attendee::create([
                        'studio_id' => $studio->id,
                        'user_id' => $user->id,
                        'first_name' => $person['first_name'],
                        'last_name' => $person['last_name'],
                        'dob' => $person['dob'],
                        'is_self' => $person['is_self'] && ! $existing->contains('is_self', true),
                        'medical_notes' => $person['medical_notes'],
                        'emergency_contact' => $person['emergency_contact'],
                        'import_id' => $import->id,
                    ] + User::madeIn($studio));
                    $done['added_people']++;
                }
            });
        }

        return $done;
    }

    /** First and last name from their own columns, or split from a full name ("Lee, Priya" or "Priya Lee"). */
    public static function names(string $first, string $last, string $full): array
    {
        if ($first !== '' || $last !== '') {
            return [$first, $last];
        }
        $full = trim((string) preg_replace('/\s+/', ' ', $full));
        if ($full === '') {
            return ['', ''];
        }
        if (str_contains($full, ',')) {
            [$surname, $given] = array_map('trim', explode(',', $full, 2));

            return [$given, $surname];
        }
        $parts = explode(' ', $full);
        $surname = count($parts) > 1 ? array_pop($parts) : '';

        return [implode(' ', $parts), $surname];
    }

    public static function email(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    public static function yes(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['yes', 'y', 'true', '1', 'x', 'on', 'checked', 'opted in', 'opted-in', 'optedin', 'subscribed'], true);
    }

    private static function personKey(string $first, string $last): string
    {
        return mb_strtolower(trim($first)).'|'.mb_strtolower(trim($last));
    }

    /** @param list<string> $emails */
    private static function usersByEmail(array $emails): Collection
    {
        $users = collect();
        foreach (array_chunk($emails, 500) as $chunk) {
            $users = $users->merge(User::whereIn('email', $chunk)->get(['id', 'email', 'first_name', 'last_name']));
        }

        return $users->keyBy('email');
    }
}
