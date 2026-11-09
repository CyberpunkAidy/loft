{{-- Where a class happens, for a program ($model = Program) or one session ($model = ClassSession). --}}
<x-select name="delivery" label="Where" :options="\App\Models\ClassSession::DELIVERIES" :value="$model->delivery ?? 'in_person'"
    hint="Online classes use the capacity for online spots. In the studio and online: capacity is the studio’s, and online spots are separate." />
<div data-show-when="delivery=hybrid">
    <x-field name="online_capacity" label="Online spots" type="number" min="1" max="1000" :value="$model->online_capacity" hint="Empty for no limit." />
</div>
<div data-show-when="delivery=online,hybrid">
    <x-field name="meeting_url" label="Meeting link" type="url" maxlength="500" :value="$model->meeting_url" placeholder="https://zoom.us/j/…"
        hint="Your Zoom, Google Meet or other link. Only people booked online see it, from {{ \App\Models\ClassSession::JOIN_OPENS_MINUTES }} minutes before class." />
</div>
