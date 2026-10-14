<x-mail::message>
# {{ $offer->buyer_name }} {{ $offer->reservation_status === 'paid' ? 'paid the reservation fee online' : 'sent proof of the reservation payment' }}

**{{ $offer->unit->name }}**, {{ $offer->project->name }} (offer {{ $offer->code }})

- Reservation fee: **₱{{ number_format((float) $offer->reservation_fee, 2) }}**
- How: {{ $offer->reservation_method === 'online' ? 'Online (PayMongo)' : 'Bank deposit / GCash, with a photo of the proof' }}
@if ($offer->reservation_reference)
- Reference: {{ $offer->reservation_reference }}
@endif
@if ($offer->reservation_note)

> {{ $offer->reservation_note }}
@endif

Check it and confirm the reservation on the offer's page. Confirming marks the unit reserved for this buyer.

<x-mail::button :url="$dashboardUrl">
Open the offer
</x-mail::button>

Thanks,<br>
jvconline
</x-mail::message>
