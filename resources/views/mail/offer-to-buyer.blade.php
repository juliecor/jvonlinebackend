<x-mail::message>
# Hi {{ \Illuminate\Support\Str::of($offer->buyer_name)->before(' ') }},

{{ $offer->agent?->name ?? $offer->realty->name }} of {{ $offer->realty->name }} prepared a sales offer for you:

**{{ $offer->unit->name }}{{ $offer->unit->unit_type ? ' · '.$offer->unit->unit_type : '' }}** — {{ $offer->project->name }}{{ $offer->project->location ? ', '.$offer->project->location : '' }}<br>
Total contract price: **₱{{ number_format((float) $offer->price, 2) }}**

It has the full payment schedule, the site plan and the location.

<x-mail::button :url="$url">
View your offer
</x-mail::button>

@if ($requirements)
Ready to reserve? You can submit your requirements on the same page — from your phone is fine.
@endif

Questions? Just reply to this email{{ $offer->agent ? ' and it goes to '.$offer->agent->name : '' }}.

{{ $offer->realty->name }}
</x-mail::message>
