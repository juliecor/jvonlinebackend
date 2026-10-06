<x-mail::message>
# {{ $realty->name }} invited you

Hello {{ $agentName }},

{{ $realty->name }} has added you as an agent on jvonline. Set your password to start sending your clients sales offers.

<x-mail::button :url="$url">
Set my password
</x-mail::button>

This link works until {{ $expiresAt->timezone('Asia/Manila')->format('F j, Y') }}. If it has expired, ask {{ $realty->name }} to send it again.

Thanks,<br>
The jvonline team
</x-mail::message>
