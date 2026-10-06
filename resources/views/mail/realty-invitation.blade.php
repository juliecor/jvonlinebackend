<x-mail::message>
# You're invited to jvonline

Hello {{ $realty->name }},

jvonline has set up a place for you at **jvconline.ph/{{ $realty->slug }}**. Fill in the registration form to tell us about your company and create your login.

<x-mail::button :url="$url">
Open the registration form
</x-mail::button>

This link works until {{ $expiresAt->timezone('Asia/Manila')->format('F j, Y') }}. If it has expired, ask us for a new one.

Thanks,<br>
The jvonline team
</x-mail::message>
