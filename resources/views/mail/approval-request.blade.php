<x-mail::message>
# Custom terms to approve

{{ $offer->agent?->name ?? 'An agent' }} made an offer with their own payment terms for **{{ $offer->buyer_name }}**:

**{{ $offer->unit->name }}** — {{ $offer->project->name }} · ₱{{ number_format((float) $offer->price, 2) }}

<x-mail::table>
| Payment | Share | Amount |
|:--|--:|--:|
@foreach ($offer->schedule as $row)
| {{ $row['label'] }}{{ isset($row['months']) ? ' ('.$row['months'].' × ₱'.number_format($row['monthly'], 2).')' : '' }} | {{ rtrim(rtrim(number_format($row['percent'], 2), '0'), '.') }}% | ₱{{ number_format($row['amount'], 2) }} |
@endforeach
</x-mail::table>

@if ($offer->approval_reason)
> {{ $offer->approval_reason }}
@endif

The buyer can't open the offer until you approve it.

<x-mail::button :url="$dashboardUrl">
Review the terms
</x-mail::button>

jvconline
</x-mail::message>
