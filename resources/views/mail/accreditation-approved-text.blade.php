Welcome aboard, {{ \Illuminate\Support\Str::of($admin->name)->before(' ') }}

{{ $brand['name'] }} has accepted the accreditation of {{ $realty->name }}. Your realty now has its own dashboard on jvconline.

Your login
Sign-in page: {{ $loginUrl }}
Username: {{ $admin->email }}
Temporary password: {{ $temporaryPassword }}

You will be asked to choose your own password the first time you sign in. Until then, keep this email to yourself: anyone who has it can sign in as you.

What happens next
1. Sign in and choose a new password.
2. Invite your agents from Agents in your dashboard.
3. Your agents can then prepare sales offers on {{ $brand['name'] }} projects and units.

{{ $brand['name'] }}
