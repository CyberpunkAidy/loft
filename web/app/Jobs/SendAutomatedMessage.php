<?php

namespace App\Jobs;

use App\Models\Attendee;
use App\Models\Automation;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Automations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * An automated email queued for later (a first-class follow-up a few hours after check-in). By the
 * time it runs, the automation may be off, or the member gone: then nothing is sent.
 */
class SendAutomatedMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $automationId,
        public int $userId,
        public ?int $attendeeId,
        public array $values,
        public string $dedupeKey,
        public ?int $domainEventId = null,
    ) {}

    public function handle(Automations $automations): void
    {
        $automation = Automation::with('studio')->find($this->automationId);
        $user = User::find($this->userId);
        if (! $automation?->active || ! $user || ! StudioUser::where('studio_id', $automation->studio_id)->where('user_id', $user->id)->exists()) {
            return;
        }
        $attendee = $this->attendeeId ? Attendee::where('studio_id', $automation->studio_id)->where('status', 'active')->find($this->attendeeId) : null;
        if ($this->attendeeId && ! $attendee) {
            return;
        }
        $automations->send($automation, $user, $attendee, $this->values, $this->dedupeKey, $this->domainEventId);
    }
}
