<x-mail::message>
# {{ $response->name }} answered your offer

**{{ \App\Models\OfferResponse::LABELS[$response->kind] ?? $response->kind }}** — {{ $offer->unit->name }}, {{ $offer->project->name }} (offer {{ $offer->code }})

@if ($response->phone)
- Mobile: {{ $response->phone }}
@endif
@if ($response->email)
- Email: {{ $response->email }}
@endif
@if ($response->contact_via)
- Prefers: {{ ucfirst($response->contact_via) }}
@endif

@if ($response->message)
> {{ $response->message }}
@endif

<x-mail::button :url="$dashboardUrl">
Open the offer
</x-mail::button>

Thanks,<br>
jvconline
</x-mail::message>
