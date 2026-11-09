{{-- Where a class happens, for a program ($model = Program) or one session ($model = ClassSession). --}}
<div class="grid-2">
    <x-select name="delivery" label="Where" :options="\App\Models\ClassSession::DELIVERIES" :value="$model->delivery ?? 'in_person'"
        hint="Online: capacity is the online spots. Both: capacity is the studio’s; online spots are set apart." />
    <x-field name="online_capacity" label="Online spots (both)" type="number" min="1" max="1000" :value="$model->online_capacity" hint="Empty for no limit." />
</div>
<x-field name="meeting_url" label="Meeting link" type="url" maxlength="500" :value="$model->meeting_url" placeholder="https://zoom.us/j/…"
    hint="Your Zoom, Google Meet or other link. Only people booked online see it, from {{ \App\Models\ClassSession::JOIN_OPENS_MINUTES }} minutes before class." />
