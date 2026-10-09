@php
    $first = \Illuminate\Support\Str::of($offer->buyer_name)->before(' ');
    $count = count($todo) + ($needsDetails ? 1 : 0);
    $accent = $brand['accent'];
    $agent = $offer->agent?->name;
    $items = collect();
    if ($needsDetails) {
        $items->push(['name' => 'Your details', 'text' => 'The buyer information form: personal info, address, work and income. About 5 minutes.', 'fix' => false]);
    }
    foreach ($todo as $item) {
        $items->push([
            'name' => $item['name'],
            'text' => $item['state'] === 'rejected' ? 'Please upload it again.'.($item['note'] ? ' '.$item['note'] : '') : ($item['help'] ?: 'Upload a clear photo or PDF.'),
            'fix' => $item['state'] === 'rejected',
        ]);
    }
@endphp
<x-branded-mail :brand="$brand" title="Your requirements" ribbon="Requirements reminder" :preheader="$count.' item'.($count === 1 ? '' : 's').' left to keep your reservation of '.$offer->unit->name.' moving.'" :footnote="($agent ? $agent.' of ' : '').$offer->sellerName().' prepared a sales offer for you, so you are receiving this reminder.'">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">You're almost there, {{ $first }}</h1>

    <p style="margin:0 0 16px;">A quick reminder about your offer for <strong>{{ $offer->unit->name }}, {{ $offer->project->name }}</strong>. To keep your reservation moving, we still need {{ $count === 1 ? 'one thing' : $count.' things' }} from you.</p>

    @if ($offer->expires_at)
        <p style="margin:0 0 16px;font-size:14px;line-height:22px;color:#6b665d;">Your offer is open until <strong style="color:#17150f;">{{ $offer->expires_at->timezone('Asia/Manila')->format('F j, Y \a\t g:i A') }}</strong> (Philippine time). If you need more time, just ask {{ $agent ?? 'your agent' }}.</p>
    @endif

    <x-branded-mail.box :accent="$accent" title="Still needed">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">
            @foreach ($items as $i => $item)
                <tr>
                    <td valign="top" width="40" style="padding:{{ $loop->first ? '0' : '14px' }} 0 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr>
                            <td align="center" width="28" height="28" bgcolor="{{ $item['fix'] ? '#b4241c' : $accent }}" style="background-color:{{ $item['fix'] ? '#b4241c' : $accent }};border-radius:14px;color:#ffffff;font-size:13px;line-height:28px;font-weight:700;">{{ $i + 1 }}</td>
                        </tr></table>
                    </td>
                    <td valign="top" style="padding:{{ $loop->first ? '0' : '14px' }} 0 0;font-size:15px;line-height:22px;color:#3d3a34;">
                        <strong style="color:#17150f;">{{ $item['name'] }}</strong>@if ($item['fix']) <span style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#b4241c;">&nbsp;Sent back</span>@endif<br>
                        <span style="color:#6b665d;font-size:14px;">{{ $item['text'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    </x-branded-mail.box>

    <x-branded-mail.button :url="$url" :accent="$accent">Complete my requirements</x-branded-mail.button>

    @if ($offer->isLocked())
        <p style="margin:0 0 16px;font-size:14px;line-height:22px;color:#6b665d;text-align:center;">This button opens your offer directly, with no username or password to type. It works for {{ $days }} days; after that, use the login {{ $agent ?? $offer->sellerName() }} gave you.</p>
    @endif

    <p style="margin:0 0 8px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#6b665d;">Good to know</p>
    <ul style="margin:0 0 20px;padding:0 0 0 22px;">
        <li style="margin:0 0 6px;">Take photos of your documents with your phone and upload them on the page. Make sure every corner is visible.</li>
        <li style="margin:0;">Your files go only to {{ $offer->realty->name }} and your agent, and are stored privately.</li>
    </ul>
</x-branded-mail>
