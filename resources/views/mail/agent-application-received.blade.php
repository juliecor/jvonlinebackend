<x-branded-mail :brand="$brand" title="New application" ribbon="New application" :preheader="$applicant->name.' applied to join '.$realty->name.'. They can sign in once you approve them.'" :footnote="'You are receiving this because you are an admin of '.$realty->name.' on jvconline.'">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">{{ $applicant->name }} wants to join your team</h1>

    <p style="margin:0 0 16px;">An agent you invited has sent in their application to join <strong>{{ $realty->name }}</strong>. They cannot sign in until you approve them.</p>

    <x-branded-mail.box :accent="$brand['accent']" title="The applicant">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">
            <tr>
                <td class="stack" style="padding:0 16px 6px 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Name</td>
                <td class="stack" style="padding:0 0 6px;font-size:15px;line-height:22px;color:#17150f;font-weight:600;">{{ $applicant->name }}</td>
            </tr>
            <tr>
                <td class="stack" style="padding:0 16px 6px 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Email</td>
                <td class="stack" style="padding:0 0 6px;font-size:15px;line-height:22px;color:#17150f;word-break:break-all;"><a href="mailto:{{ $applicant->email }}" style="color:{{ $brand['accent'] }};">{{ $applicant->email }}</a></td>
            </tr>
            <tr>
                <td class="stack" style="padding:0 16px 0 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Mobile</td>
                <td class="stack" style="padding:0;font-size:15px;line-height:22px;color:#17150f;">{{ $applicant->phone ?? 'Not given' }}</td>
            </tr>
        </table>
    </x-branded-mail.box>

    <x-branded-mail.button :url="$reviewUrl" :accent="$brand['accent']">Review the application</x-branded-mail.button>

    <p style="margin:0;font-size:14px;line-height:22px;color:#6b665d;">You can approve or reject them on the Agents page. They are told by email either way. Reply to this email to write to {{ \Illuminate\Support\Str::of($applicant->name)->before(' ') }} directly.</p>
</x-branded-mail>
