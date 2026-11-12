<?php

namespace App\Http\Resources\Api;

use App\Models\ClassSession;
use App\Models\Series;
use App\Services\SeriesSignups;
use Illuminate\Http\Request;

/**
 * A series (decision 47): a run of classes sold as one, with what signing up now costs. With
 * "classes" loaded (the detail endpoint), each class's times; with a member's token, who on
 * their account is signed up.
 *
 * @mixin Series
 */
class SeriesResource extends ApiResource
{
    /** @var array<int, string> attendee id => enrollment status, for the member asking */
    public array $mine = [];

    public bool $withClasses = false;

    public function toArray(Request $request): array
    {
        $studio = $this->studio;
        $quote = app(SeriesSignups::class)->quote($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'program' => ['id' => $this->program->id, 'title' => $this->program->title, 'excerpt' => $this->program->excerpt, 'schedule' => $this->program->scheduleLabel()],
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'dates' => $this->datesLabel(),
            'currency' => $studio->currency,
            'price_cents' => $this->price_cents,          // the whole series
            'price_now_cents' => $quote['price_cents'],   // signing up now (less, once it's started)
            'classes_total' => $quote['total'],
            'classes_left' => $quote['left'],
            'spots_left' => $quote['spots_left'],
            'open' => $quote['open'],
            'closed_message' => $quote['closed'],
            'join_until_class' => $this->join_until_class,
            'makeups' => $this->makeups,
            'drop_ins' => $this->allow_dropins,
            'url' => route('studio.series', ['studio' => $studio, 'series' => $this->id]),
            'classes' => $this->when($this->withClasses, fn () => $this->resource->classes()->map(fn (ClassSession $c) => [
                'session_id' => $c->id,
                'starts_at' => self::utc($c->starts_at),
                'starts_at_local' => self::local($studio, $c->starts_at),
                'done' => $c->hasStarted(),
            ])->values()),
            'mine' => $this->when($this->mine !== [], fn () => collect($this->mine)->map(fn ($status, $id) => ['attendee_id' => $id, 'status' => $status])->values()),
        ];
    }
}
