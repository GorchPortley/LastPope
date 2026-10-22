<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="color-scheme" content="light dark" />
    <meta name="supported-color-schemes" content="light dark" />
    <style>
        @media only screen and (max-width: 640px) {
            .inner-body, .header-inner, .footer {
                width: 100% !important;
            }

            .content-cell {
                padding: 24px 20px !important;
            }

            .wordmark-cell {
                padding: 18px 20px 16px 20px !important;
            }

            .tagline-cell {
                display: none !important;
            }

            .footer-cell {
                padding: 16px 20px 28px 20px !important;
            }

            .body {
                padding: 16px 12px 4px 12px !important;
            }

            .inner-body {
                border-radius: 6px !important;
            }

            .content-cell > table:not(.action):not(.panel):not(.subcopy) th, .content-cell > table:not(.action):not(.panel):not(.subcopy) td, .table th, .table td {
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
        }

        @media only screen and (max-width: 500px) {
            .button {
                width: 100% !important;
            }
        }

        /* Dark mode (Apple Mail, Outlook apps, ...): the brand band stays; Gmail darkens on its own */
        @media (prefers-color-scheme: dark) {
            body, .wrapper, .body {
                background-color: #17101f !important;
            }

            .inner-body {
                background-color: #221a2e !important;
                border-color: #3b2f4d !important;
            }

            h1, h2, h3, strong, .footer p.footer-brand {
                color: #ffffff !important;
            }

            p, li, ul, ol, td, .table td, blockquote {
                color: #e5e7eb !important;
            }

            a {
                color: #d8b4fe !important;
            }

            .wordmark-cell a, .wordmark {
                color: #ffffff !important;
            }

            .button, .button-primary, .button-success, .button-error {
                color: #ffffff !important;
            }

            .table th, .content-cell > table th {
                background-color: #2e2340 !important;
                border-bottom-color: #4c3a6b !important;
                color: #e9d5ff !important;
            }

            .table td, .content-cell > table td {
                border-bottom-color: #3b2f4d !important;
            }

            .panel-content {
                background-color: #2e2340 !important;
            }

            .subcopy {
                border-top-color: #3b2f4d !important;
            }

            .subcopy p, .footer p, .footer p.footer-legal {
                color: #a79fb5 !important;
            }

            .footer a {
                color: #d8b4fe !important;
            }
        }
    </style>
</head>
<body>

    <table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    {{ $header ?? '' }}

                    <!-- Email Body -->
                    <tr>
                        <td class="body" width="100%" cellpadding="0" cellspacing="0">
                            <table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
                                <!-- Body content -->
                                <tr>
                                    <td class="content-cell">
                                        {{ Illuminate\Mail\Markdown::parse($slot) }}

                                        {{ $subcopy ?? '' }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{ $footer ?? '' }}
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
