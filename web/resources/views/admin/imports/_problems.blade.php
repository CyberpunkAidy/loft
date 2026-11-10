{{-- Rows an import skips, and things it changed on the way in. --}}
@if ($skipped)
    <details class="import-problems mt">
        <summary>{{ count($skipped) + $moreSkipped }} {{ \Illuminate\Support\Str::plural('row', count($skipped) + $moreSkipped) }} skipped</summary>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Row</th><th>Name</th><th>Why</th></tr></thead>
                <tbody>
                @foreach (array_slice($skipped, 0, 300) as $row)
                    <tr><td>{{ $row['line'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['reason'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if (count($skipped) + $moreSkipped > 300)<p class="small muted">And {{ count($skipped) + $moreSkipped - 300 }} more.</p>@endif
    </details>
@endif
@if ($warnings)
    <details class="import-problems mt">
        <summary>{{ count($warnings) + $moreWarnings }} {{ \Illuminate\Support\Str::plural('note', count($warnings) + $moreWarnings) }}</summary>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Row</th><th>Name</th><th>Note</th></tr></thead>
                <tbody>
                @foreach (array_slice($warnings, 0, 300) as $row)
                    <tr><td>{{ $row['line'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['message'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
