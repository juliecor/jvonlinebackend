<x-branded-mail :brand="$brand" title="Your application" ribbon="Application update" :preheader="'An update on your application to '.$realty->name.'.'" :footnote="'You are receiving this because you applied to join '.$realty->name.'.'">
    <h1 class="heading" style="margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:36px;font-weight:700;color:#17150f;">About your application</h1>

    <p style="margin:0 0 16px;">Hello {{ \Illuminate\Support\Str::of($agent->name)->before(' ') }},</p>
    <p style="margin:0 0 16px;">Thank you for applying to join <strong>{{ $realty->name }}</strong>. After looking at your application, the team has decided not to approve it at this time, so your account stays closed and you cannot sign in.</p>
    <p style="margin:0 0 16px;">This is not a judgement of you. If you think it was a mistake, or you would like to know more, please get in touch with them directly. You can reply to this email{{ $realty->email || $realty->phone ? ', or reach them here:' : '.' }}</p>

    @if ($realty->email || $realty->phone)
        <x-branded-mail.box :accent="$brand['accent']" :title="$realty->name">
            @if ($realty->email)
                <div style="margin:0 0 4px;"><a href="mailto:{{ $realty->email }}" style="color:{{ $brand['accent'] }};">{{ $realty->email }}</a></div>
            @endif
            @if ($realty->phone)
                <div>{{ $realty->phone }}</div>
            @endif
        </x-branded-mail.box>
    @endif

    <p style="margin:0;font-size:14px;line-height:22px;color:#6b665d;">We wish you well.</p>
</x-branded-mail>
