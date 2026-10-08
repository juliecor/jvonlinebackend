<x-branded-mail :brand="$brand" title="You're approved" ribbon="Application approved" :preheader="$realty->name.' approved your application. Sign in to start.'" :footnote="'You are receiving this because you applied to join '.$realty->name.'.'">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">You're in, {{ \Illuminate\Support\Str::of($agent->name)->before(' ') }}</h1>

    <p style="margin:0 0 16px;"><strong>{{ $realty->name }}</strong> has approved your application. Your account is open, and you can sign in now with the email and password you chose when you applied.</p>

    <x-branded-mail.box :accent="$brand['accent']" title="Your login">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;">
            <tr>
                <td class="stack" style="padding:0 16px 6px 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Sign-in page</td>
                <td class="stack" style="padding:0 0 6px;font-size:15px;line-height:22px;color:#17150f;word-break:break-all;"><a href="{{ $loginUrl }}" style="color:{{ $brand['accent'] }};">{{ preg_replace('#^https?://#', '', $loginUrl) }}</a></td>
            </tr>
            <tr>
                <td class="stack" style="padding:0 16px 0 0;font-size:14px;line-height:22px;color:#6b665d;white-space:nowrap;" valign="top">Username</td>
                <td class="stack" style="padding:0;font-size:15px;line-height:22px;color:#17150f;font-weight:600;word-break:break-all;">{{ $agent->email }}</td>
            </tr>
        </table>
    </x-branded-mail.box>

    <x-branded-mail.button :url="$loginUrl" :accent="$brand['accent']">Sign in to your dashboard</x-branded-mail.button>

    <p style="margin:0 0 8px;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#6b665d;">What you can do</p>
    <ul style="margin:0;padding:0 0 0 22px;">
        <li style="margin:0 0 6px;">Send buyers a sales offer with the price, payment schedule and your contact details.</li>
        <li style="margin:0 0 6px;">See when a buyer opens it, and get their answer by email.</li>
        <li style="margin:0;">Find every project, unit and price list in one place, on your phone.</li>
    </ul>
</x-branded-mail>
