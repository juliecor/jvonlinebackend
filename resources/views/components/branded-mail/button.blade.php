{{-- A call-to-action that stays a button in Outlook too (a padded link inside a coloured cell). --}}
@props(['url', 'accent'])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="border-collapse:separate;margin:28px auto;">
    <tr>
        <td align="center" bgcolor="{{ $accent }}" style="background-color:{{ $accent }};border-radius:8px;">
            <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:16px 34px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;letter-spacing:0.02em;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
