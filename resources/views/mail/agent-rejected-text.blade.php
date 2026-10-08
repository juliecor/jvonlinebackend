About your application

Hello {{ \Illuminate\Support\Str::of($agent->name)->before(' ') }},

Thank you for applying to join {{ $realty->name }}. After looking at your application, the team has decided not to approve it at this time, so your account stays closed and you cannot sign in.

This is not a judgement of you. If you think it was a mistake, or you would like to know more, please get in touch with them directly. You can reply to this email.
@if ($realty->email || $realty->phone)

{{ $realty->name }}
@if ($realty->email)
{{ $realty->email }}
@endif
@if ($realty->phone)
{{ $realty->phone }}
@endif
@endif

We wish you well.

{{ $brand['name'] }}
