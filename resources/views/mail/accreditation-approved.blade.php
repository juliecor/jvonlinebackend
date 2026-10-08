<x-branded-mail :brand="$brand" title="You're accredited" ribbon="Accreditation accepted" preheader="Your username and temporary password are inside.">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">Welcome aboard, {{ \Illuminate\Support\Str::of($admin->name)->before(' ') }}</h1>

    <p style="margin:0 0 16px;"><strong>{{ $brand['name'] }}</strong> has accepted the accreditation of <strong>{{ $realty->name }}</strong>. Your realty now has its own dashboard on jvconline.</p>

    <x-branded-mail.box :accent="$brand['accent']" title="Your login">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">
            <tr>
                <td class="stack" style="padding:0 16px 8px 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Sign-in page</td>
                <td class="stack" style="padding:0 0 8px;font-size:15px;line-height:22px;color:#17150f;word-break:break-all;"><a href="{{ $loginUrl }}" style="color:{{ $brand['accent'] }};">{{ preg_replace('#^https?://#', '', $loginUrl) }}</a></td>
            </tr>
            <tr>
                <td class="stack" style="padding:0 16px 8px 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Username</td>
                <td class="stack" style="padding:0 0 8px;font-size:15px;line-height:22px;color:#17150f;font-weight:600;word-break:break-all;">{{ $admin->email }}</td>
            </tr>
            <tr>
                <td class="stack" style="padding:0 16px 0 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Temporary password</td>
                <td class="stack" style="padding:0;font-size:17px;line-height:22px;color:#17150f;font-weight:700;font-family:'SFMono-Regular',Menlo,Consolas,monospace;letter-spacing:0.04em;">{{ $temporaryPassword }}</td>
            </tr>
        </table>
    </x-branded-mail.box>

    <x-branded-mail.button :url="$loginUrl" :accent="$brand['accent']">Sign in to your dashboard</x-branded-mail.button>

    <p style="margin:0 0 20px;"><strong>You will be asked to choose your own password the first time you sign in.</strong> Until then, keep this email to yourself: anyone who has it can sign in as you.</p>

    <p style="margin:0 0 8px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#6b665d;">What happens next</p>
    <ol style="margin:0;padding:0 0 0 22px;">
        <li style="margin:0 0 6px;">Sign in and choose a new password.</li>
        <li style="margin:0 0 6px;">Invite your agents from <strong>Agents</strong> in your dashboard.</li>
        <li style="margin:0;">Your agents can then prepare sales offers on {{ $brand['name'] }} projects and units.</li>
    </ol>
</x-branded-mail>
