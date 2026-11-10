<?php

namespace App\Services;

use App\Events\MemberJoined;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Joining a studio, with a new account or an existing one, from the website or the app. */
class Memberships
{
    public function __construct(
        private readonly DomainEvents $events,
        private readonly Consent $consent,
    ) {}

    /** @return array<string, mixed> validation rules for a new account */
    public static function registrationRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', Password::min(8)],
            'is_student' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Make an existing account a member (if it isn't already). Marketing email only if they opted in.
     * Returns false if they were already in the studio.
     */
    public function join(Studio $studio, User $user, bool $marketing): bool
    {
        if ($user->hasRoleIn($studio)) {
            return false;
        }
        DB::transaction(fn () => $this->membership($studio, $user, $marketing));
        $user->forgetMemberships();
        MemberJoined::dispatch($studio, $user);

        return true;
    }

    /**
     * A new account, a member of the studio. If they take classes themselves ("is_student"), they're
     * added as the first person on the account.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone?: ?string, password: string, is_student?: bool}  $data
     */
    public function register(Studio $studio, array $data, bool $marketing): User
    {
        $user = DB::transaction(function () use ($studio, $data, $marketing) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ] + User::termsAccepted()); // the join form and the app say signing up agrees to them
            $this->membership($studio, $user, $marketing);
            if (! empty($data['is_student'])) {
                $studio->attendees()->create(['user_id' => $user->id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'is_self' => true]);
            }

            return $user;
        });
        MemberJoined::dispatch($studio, $user);

        return $user;
    }

    /** The membership row, its "joined" event, and marketing consent if they ticked the box. */
    private function membership(Studio $studio, User $user, bool $marketing): StudioUser
    {
        $membership = $studio->studioUsers()->firstOrCreate(['user_id' => $user->id], ['role' => 'member']);
        $this->events->record('member.joined', $studio, [], $user->id);
        if ($marketing) {
            $this->consent->setMarketingEmail($membership, true, 'join');
        }

        return $membership;
    }
}
