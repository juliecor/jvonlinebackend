<x-mail::message>
@if ($offer->approval_status === 'approved')
# Approved

Your custom terms for **{{ $offer->buyer_name }}** ({{ $offer->unit->name }}, {{ $offer->project->name }}) were approved{{ $offer->approver ? ' by '.$offer->approver->name : '' }}.

@if ($emailedTo)
The offer has been emailed to {{ $emailedTo }}.
@else
You can send the link to the buyer now.
@endif
@else
# Sent back

Your custom terms for **{{ $offer->buyer_name }}** ({{ $offer->unit->name }}, {{ $offer->project->name }}) need changes{{ $offer->approver ? ' — '.$offer->approver->name.' says:' : ':' }}

> {{ $offer->approval_note }}

Edit the terms on the offer's page and send them for approval again.
@endif

<x-mail::button :url="$dashboardUrl">
Open the offer
</x-mail::button>

jvconline
</x-mail::message>
