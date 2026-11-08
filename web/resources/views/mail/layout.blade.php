{{--
    The shell every HTML email uses, styled like the site. Email apps ignore most modern CSS, so
    this is tables with inline styles: Gmail and Outlook render it the same as Apple Mail. The web
    fonts load where an app allows it (Apple Mail, iOS); everywhere else the system font stands in.
    Sections: title, preheader (the inbox preview), header, bar (the color strip's CSS background),
    content, footer.
--}}
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title')</title>
    <style>
        @font-face { font-family: "Inter Variable"; font-weight: 100 900; font-display: swap; src: url({{ asset('fonts/inter-latin-wght-normal.woff2') }}) format("woff2"); }
        @font-face { font-family: "Bricolage Grotesque Variable"; font-weight: 200 800; font-display: swap; src: url({{ asset('fonts/bricolage-grotesque-latin-wght-normal.woff2') }}) format("woff2"); }
        body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; }
        a { text-underline-offset: 2px; }
        @media (max-width: 600px) {
            .shell { padding: 20px 0 !important; }
            .card { border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
            .h1 { font-size: 24px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f7f6f3;">
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#f7f6f3;">@yield('preheader')&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f6f3;">
        <tr>
            <td class="shell" align="center" style="padding:36px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                    <tr>
                        <td class="px" style="padding:0 4px 18px;">@yield('header')</td>
                    </tr>
                    <tr>
                        <td class="card" style="background:#ffffff;border:1px solid #e9e6e0;border-radius:20px;overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td height="5" style="height:5px;line-height:5px;font-size:0;@yield('bar')">&nbsp;</td></tr>
                                <tr>
                                    <td class="px" style="padding:30px 34px 34px;font-family:'Inter Variable',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#3a3545;">
                                        @yield('content')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="px" style="padding:22px 6px 0;font-family:'Inter Variable',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12.5px;line-height:1.6;color:#6c6776;">
                            @yield('footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
