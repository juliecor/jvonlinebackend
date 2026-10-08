{{ $applicant->name }} wants to join your team

An agent you invited has sent in their application to join {{ $realty->name }}. They cannot sign in until you approve them.

Name: {{ $applicant->name }}
Email: {{ $applicant->email }}
Mobile: {{ $applicant->phone ?? 'Not given' }}

Review the application: {{ $reviewUrl }}

You can approve or reject them on the Agents page. They are told by email either way. Reply to this email to write to {{ \Illuminate\Support\Str::of($applicant->name)->before(' ') }} directly.

{{ $brand['name'] }}
