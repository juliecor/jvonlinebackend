Hi {{ \Illuminate\Support\Str::of($offer->buyer_name)->before(' ') }},

A reminder about your offer for {{ $offer->unit->name }}, {{ $offer->project->name }}. To keep your reservation moving, we still need:

@if ($needsDetails)
- Your details (the buyer information form)
@endif
@foreach ($todo as $item)
- {{ $item['name'] }}@if ($item['state'] === 'rejected') (please upload it again{{ $item['note'] ? ': '.$item['note'] : '' }})@endif

@endforeach

@if ($offer->expires_at)
Your offer is open until {{ $offer->expires_at->timezone('Asia/Manila')->format('F j, Y \a\t g:i A') }} (Philippine time). If you need more time, ask {{ $offer->agent?->name ?? 'your agent' }}.

@endif
Complete my requirements: {{ $url }}

@if ($offer->isLocked())
This link opens your offer directly, with no username or password to type. It works for {{ $days }} days; after that, use the login {{ $offer->agent?->name ?? $offer->sellerName() }} gave you.

@endif
Take photos of your documents with your phone and upload them on the page.
