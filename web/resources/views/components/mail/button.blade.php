{{-- A pill button that also works in Outlook (the color sits on the table cell, not just the link). --}}
@props(['url', 'background', 'color' => '#ffffff'])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 6px;">
    <tr>
        <td bgcolor="{{ $background }}" style="border-radius:999px;background:{{ $background }};">
            <a href="{{ $url }}" style="display:inline-block;padding:13px 24px;border-radius:999px;font-family:'Inter Variable',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;line-height:1.2;color:{{ $color }};text-decoration:none;">{{ $slot }}&nbsp;&rarr;</a>
        </td>
    </tr>
</table>
