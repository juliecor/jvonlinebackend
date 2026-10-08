{{-- A tinted panel with an accent edge, for what the reader has to keep or have ready. --}}
@props(['accent', 'title' => null])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:24px 0;background-color:#faf8f5;border:1px solid #e6e2db;border-left:4px solid {{ $accent }};border-radius:8px;">
    <tr>
        <td style="padding:20px 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#3d3a34;">
            @if ($title)
                <div style="margin:0 0 10px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#6b665d;">{{ $title }}</div>
            @endif
            {{ $slot }}
        </td>
    </tr>
</table>
