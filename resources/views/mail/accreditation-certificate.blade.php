<x-mail::message>
@if ($renewed)
# Your accreditation is renewed

**{{ $certificate->firm_name }}** stays accredited by {{ $certificate->developer_name }}. Your new certificate of accreditation is ready.
@else
# Congratulations, {{ $certificate->firm_name }}!

You are now **fully accredited** by {{ $certificate->developer_name }}. Your signed accreditation agreement was checked and accepted, and your certificate of accreditation is ready.
@endif

<x-mail::panel>
**CERTIFICATE OF ACCREDITATION**<br>
No. {{ $certificate->number }}<br>
Valid from **{{ $certificate->valid_from->format('F j, Y') }}** to **{{ $certificate->valid_until->format('F j, Y') }}**
</x-mail::panel>

<x-mail::button :url="$url">
View your certificate
</x-mail::button>

Print it or save it as a PDF from your dashboard. Anyone can check that it is real at {{ rtrim((string) config('app.frontend_url'), '/') }}/verify/{{ $certificate->code }}

Thank you for selling with {{ $certificate->developer_name }}.

jvconline
</x-mail::message>
