<x-mail::message>
# Hi {{ \Illuminate\Support\Str::of($offer->buyer_name)->before(' ') }},

@if ($offer->reservation_status === 'confirmed')
Good news: **{{ $offer->unit->name }}** in {{ $offer->project->name }} is now **reserved for you**. {{ $offer->realty->name }} received your reservation fee of ₱{{ number_format((float) $offer->reservation_fee, 2) }}.

Your sales offer shows the payment schedule from here. {{ $offer->agent?->name ?? $offer->sellerName() }} will be in touch about the next steps.
@else
{{ $offer->realty->name }} could not confirm your reservation payment for **{{ $offer->unit->name }}** in {{ $offer->project->name }} yet.
@if ($offer->reservation_note)

> {{ $offer->reservation_note }}
@endif

You can send a new proof of payment from your offer page, or reply to this email to reach {{ $offer->agent?->name ?? $offer->sellerName() }}.
@endif

<x-mail::button :url="$url">
Open your offer
</x-mail::button>

{{ $offer->sellerName() }}@if ($offer->broker)<br>
in partnership with {{ $offer->realty->name }}@endif
</x-mail::message>
