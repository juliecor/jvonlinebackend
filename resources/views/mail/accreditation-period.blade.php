<x-mail::message>
@if ($forDeveloper)
# {{ $form->realty->name }}'s accreditation {{ $state === 'ended' ? 'has ended' : 'ends soon' }}

@if ($state === 'ended')
It ended on **{{ $form->accredited_until->format('M j, Y') }}**. Its people can't make new offers until you renew it; offers already sent go on.
@else
It ends on **{{ $form->accredited_until->format('M j, Y') }}**. Renew it before then if they keep selling for {{ $form->developer->name }}.
@endif

<x-mail::button :url="$url">
Open {{ $form->realty->name }}
</x-mail::button>
@else
# Your accreditation {{ $state === 'ended' ? 'has ended' : 'ends soon' }}

@if ($state === 'ended')
Your accreditation with {{ $form->developer->name }} ended on **{{ $form->accredited_until->format('M j, Y') }}**. You can still follow the offers you already sent, but new offers have to wait until {{ $form->developer->name }} renews it.
@else
Your accreditation with {{ $form->developer->name }} ends on **{{ $form->accredited_until->format('M j, Y') }}**. Talk to them about renewing it so you can keep making offers.
@endif

<x-mail::button :url="$url">
Open your accreditation
</x-mail::button>
@endif

jvconline
</x-mail::message>
