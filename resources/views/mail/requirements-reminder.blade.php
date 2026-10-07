<x-mail::message>
# Hi {{ \Illuminate\Support\Str::of($offer->buyer_name)->before(' ') }},

A reminder about your offer for **{{ $offer->unit->name }}, {{ $offer->project->name }}**. To keep your reservation moving, we still need:

@if ($needsDetails)
- **Your details** (the buyer information form)
@endif
@foreach ($todo as $item)
- **{{ $item['name'] }}**@if ($item['state'] === 'rejected') — please upload it again{{ $item['note'] ? ': '.$item['note'] : '' }}@endif

@endforeach

<x-mail::button :url="$url">
Complete my requirements
</x-mail::button>

You can take photos of your documents with your phone and upload them on the page.

Questions? Just reply to this email{{ $offer->agent ? ' and it goes to '.$offer->agent->name : '' }}.

{{ $offer->realty->name }}
</x-mail::message>
