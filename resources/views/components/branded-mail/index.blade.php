{{--
    The frame of a branded HTML email: logo header, accent ribbon, body, footer.
    Table layout with inline styles, so it renders the same in Gmail, Outlook and Apple Mail.

    <x-branded-mail :brand="$brand" title="..." ribbon="..." preheader="...">body</x-branded-mail>
    $brand comes from Realty::mailBrand() (name, logo, accent, site).
--}}
@props(['brand', 'title', 'ribbon' => null, 'preheader' => null])
@php
    $accent = $brand['accent'];
    $sans = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $title }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .card { border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
            .pad { padding-left: 22px !important; padding-right: 22px !important; }
            .logo { width: 150px !important; }
            .heading { font-size: 25px !important; line-height: 31px !important; }
            .stack { display: block !important; width: 100% !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f1efea;">
@if ($preheader)
    {{-- The preview line mail apps show next to the subject; hidden in the message itself. --}}
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $preheader }}&#8199;&zwnj;&#8199;&zwnj;&#8199;&zwnj;&#8199;&zwnj;&#8199;&zwnj;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;background-color:#f1efea;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" class="card" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e6e2db;border-radius:20px;overflow:hidden;box-shadow:0 18px 48px rgba(23,21,15,0.10);">

                {{-- LOGO HEADER --}}
                <tr>
                    <td align="center" style="background-color:#ffffff;padding:38px 32px 30px;">
                        @if ($brand['logo'])
                            <img class="logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" width="190" style="display:block;border:0;outline:none;text-decoration:none;width:190px;max-width:100%;height:auto;">
                        @else
                            <span style="font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:30px;font-weight:700;color:{{ $accent }};">{{ $brand['name'] }}</span>
                        @endif
                    </td>
                </tr>

                {{-- RIBBON: says at a glance what this email is about --}}
                @if ($ribbon)
                    <tr>
                        <td align="center" bgcolor="{{ $accent }}" style="background-color:{{ $accent }};padding:14px 24px;">
                            <span style="font-family:{{ $sans }};font-size:13px;line-height:18px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#ffffff;">{{ $ribbon }}</span>
                        </td>
                    </tr>
                @endif

                {{-- BODY --}}
                <tr>
                    <td class="pad" style="padding:40px 44px 38px;font-family:{{ $sans }};font-size:16px;line-height:26px;color:#3d3a34;">
                        {{ $slot }}
                    </td>
                </tr>

                {{-- FOOTER --}}
                <tr>
                    <td class="pad" align="center" style="background-color:#faf8f5;border-top:1px solid #e6e2db;padding:26px 44px;font-family:{{ $sans }};font-size:12px;line-height:19px;color:#8a847a;">
                        <strong style="color:#5a554d;">{{ $brand['name'] }}</strong><br>
                        <a href="{{ $brand['site'] }}" style="color:{{ $accent }};text-decoration:none;">{{ preg_replace('#^https?://#', '', $brand['site']) }}</a>
                    </td>
                </tr>
            </table>

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:600px;">
                <tr>
                    <td align="center" style="padding:18px 24px 0;font-family:{{ $sans }};font-size:11px;line-height:17px;color:#a39d92;">
                        You are receiving this because of your accreditation with {{ $brand['name'] }}. Please do not forward this email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
