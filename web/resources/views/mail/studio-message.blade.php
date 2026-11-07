@php $accent = $studio->accent(); @endphp
<!doctype html>
<html lang="en">
<body style="margin:0;background:#f6f5f4;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1c1917">
<div style="max-width:560px;margin:24px auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e7e5e4">
    <div style="background:{{ $accent }};color:#ffffff;padding:16px 24px;font-weight:700;font-size:18px">{{ $studio->name }}</div>
    <div style="padding:24px;font-size:15px;line-height:1.55">
        <h1 style="font-size:20px;margin:0 0 12px">{{ $headline }}</h1>
        @foreach ($lines as $line)
            <p style="margin:0 0 12px">{{ $line }}</p>
        @endforeach
        @if ($details)
            <table role="presentation" style="width:100%;border-collapse:collapse;margin:8px 0 16px;background:#fafaf9;border-radius:8px">
                @foreach ($details as $label => $value)
                    <tr><td style="padding:8px 12px;color:#78716c;font-size:13px;width:90px;vertical-align:top">{{ $label }}</td><td style="padding:8px 12px;font-weight:600">{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif
        @foreach ($after as $line)
            <p style="margin:0 0 12px;color:#57534e;font-size:14px">{{ $line }}</p>
        @endforeach
        @if ($actionUrl)
            <p style="margin:20px 0 4px"><a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:999px">{{ $actionLabel }}</a></p>
        @endif
    </div>
    <div style="padding:12px 24px 20px;font-size:12px;color:#78716c">
        Manage your classes and billing in <a href="{{ route('studio.account', $studio) }}" style="color:{{ $accent }}">your account</a>.
        @if ($studio->contact_email) Questions? Just reply to this email.@endif
    </div>
</div>
</body>
</html>
