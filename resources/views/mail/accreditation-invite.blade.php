<x-branded-mail :brand="$brand" title="Realty accreditation" ribbon="Realty accreditation" :preheader="'You are invited to get accredited with '.$brand['name'].'. The form takes about 10 minutes.'">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">You're invited to become an accredited realty</h1>

    <p style="margin:0 0 16px;">Hello,</p>
    <p style="margin:0 0 16px;"><strong>{{ $brand['name'] }}</strong> has invited your realty to be accredited. Once you are, your agents can sell our projects and units through their own dashboard on jvconline.</p>
    <p style="margin:0 0 4px;">Open the form, tell us about your realty and attach your registration documents. It takes about 10 minutes.</p>

    <x-branded-mail.button :url="$url" :accent="$brand['accent']">Open the accreditation form</x-branded-mail.button>

    <x-branded-mail.box :accent="$brand['accent']" title="Have these ready">
        <ul style="margin:0;padding:0 0 0 20px;">
            <li style="margin:0 0 6px;">Your SEC registration, or DTI registration if you are a sole proprietor</li>
            <li style="margin:0 0 6px;">A board or partnership resolution naming your representative (corporations)</li>
            <li style="margin:0 0 6px;">Your tax identification numbers, and your PRC registration</li>
            <li style="margin:0;">Your HLURB registration, if you have one</li>
        </ul>
    </x-branded-mail.box>

    <p style="margin:0 0 12px;font-size:14px;line-height:22px;color:#6b665d;">This link works until <strong>{{ $expiresAt->timezone('Asia/Manila')->format('F j, Y') }}</strong> and can be used once. If it has expired, ask {{ $brand['name'] }} to send it again.</p>
    <p style="margin:0;font-size:13px;line-height:20px;color:#8a847a;">If the button doesn't open, copy this address into your browser:<br><a href="{{ $url }}" style="color:{{ $brand['accent'] }};word-break:break-all;">{{ $url }}</a></p>
</x-branded-mail>
