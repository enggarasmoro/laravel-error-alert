<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $payload['service'] ?? 'Application' }} error alert</title>
    <style>
        body,
        table,
        td,
        p,
        h1 {
            margin: 0;
            padding: 0;
        }

        table {
            border-collapse: collapse;
            border-spacing: 0;
        }

        .email-canvas {
            padding: 12px !important;
        }

        .masthead,
        .content,
        .footer {
            padding-left: 22px !important;
            padding-right: 22px !important;
        }

        .incident-title {
            font-size: 34px !important;
            line-height: 39px !important;
        }

        .break-word {
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .fact-label,
        .fact-value {
            display: block !important;
            width: 100% !important;
        }

        .fact-label {
            padding: 14px 0 3px !important;
            border-bottom: 0 !important;
        }

        .fact-value {
            padding: 0 0 14px !important;
            border-bottom: 1px solid #e3e0da !important;
        }

        .fact-value-last {
            border-bottom: 0 !important;
        }

        @media only screen and (min-width: 600px) {
            .email-canvas {
                padding: 32px 20px !important;
            }

            .masthead,
            .content,
            .footer {
                padding-left: 48px !important;
                padding-right: 48px !important;
            }

            .incident-title {
                font-size: 44px !important;
                line-height: 49px !important;
            }

            .fact-label,
            .fact-value {
                display: table-cell !important;
            }

            .fact-label {
                width: 35% !important;
                padding: 14px 10px 14px 0 !important;
                border-bottom: 1px solid #e3e0da !important;
            }

            .fact-value {
                width: 65% !important;
                padding: 14px 0 !important;
                border-bottom: 1px solid #e3e0da !important;
            }

            .fact-label-last,
            .fact-value-last {
                border-bottom: 0 !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f2f0ec;color:#20201e;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">{{ (int) ($payload['status'] ?? 500) }} error in {{ $payload['service'] ?? 'Application' }} — {{ $payload['error_code'] ?? 'unclassified incident' }}</div>

    <table role="presentation" width="100%" style="width:100%;background-color:#f2f0ec;">
        <tr>
            <td class="email-canvas" align="center" style="padding:12px;">
                <table role="presentation" width="640" style="width:100%;max-width:640px;background-color:#ffffff;border:1px solid #d9d5ce;">
                    <tr>
                        <td style="height:6px;background-color:#b83a2f;font-size:0;line-height:6px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="masthead" style="padding:24px 22px 23px;border-bottom:1px solid #d9d5ce;">
                            <table role="presentation" width="100%" style="width:100%;">
                                <tr>
                                    <td valign="middle">
                                        <div style="color:#706d67;font-family:'Courier New',Courier,monospace;font-size:10px;font-weight:bold;letter-spacing:1.6px;line-height:15px;">ENGGARASMORO / INCIDENT DESK</div>
                                        <div class="break-word" style="margin-top:7px;color:#20201e;font-size:21px;font-weight:bold;line-height:27px;">{{ $payload['service'] ?? 'Application' }}</div>
                                    </td>
                                    <td width="112" align="right" valign="middle" style="width:112px;">
                                        <span style="display:inline-block;padding:7px 10px;border:1px solid #20201e;color:#20201e;font-family:'Courier New',Courier,monospace;font-size:10px;font-weight:bold;letter-spacing:1.2px;line-height:12px;">{{ strtoupper((string) ($payload['environment'] ?? 'unknown')) }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding:36px 22px 38px;">
                            <div style="color:#b83a2f;font-family:'Courier New',Courier,monospace;font-size:10px;font-weight:bold;letter-spacing:1.7px;line-height:15px;">SERVER-SIDE INCIDENT / {{ (int) ($payload['status'] ?? 500) }}</div>
                            <h1 class="incident-title" style="margin:11px 0 0;color:#20201e;font-family:Georgia,'Times New Roman',serif;font-size:34px;font-weight:normal;letter-spacing:-1.2px;line-height:39px;">An incident needs<br>your attention.</h1>
                            <p style="margin:17px 0 0;max-width:500px;color:#65625d;font-size:14px;line-height:22px;">A server-side failure was captured by the alert pipeline. Sensitive values have been removed; operational context remains intact.</p>

                            <table role="presentation" width="100%" style="width:100%;margin-top:28px;background-color:#20201e;color:#ffffff;">
                                <tr>
                                    <td valign="top" style="width:68%;padding:17px 16px;border-right:1px solid #4b4a47;">
                                        <div style="color:#aaa69f;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1.2px;line-height:13px;">SIGNAL SOURCE</div>
                                        <div class="break-word" style="margin-top:5px;font-size:13px;font-weight:bold;line-height:19px;">{{ $payload['source'] ?? 'Application' }} reported an error</div>
                                    </td>
                                    <td width="32%" valign="top" style="width:32%;padding:17px 16px;">
                                        <div style="color:#aaa69f;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1.2px;line-height:13px;">HTTP STATUS</div>
                                        <div style="margin-top:5px;color:#f06b5f;font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:25px;">{{ (int) ($payload['status'] ?? 500) }}</div>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top:32px;padding-bottom:9px;border-bottom:1px solid #20201e;color:#706d67;font-family:'Courier New',Courier,monospace;font-size:10px;font-weight:bold;letter-spacing:1.5px;line-height:15px;">INCIDENT COORDINATES</div>
                            <table role="presentation" width="100%" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td class="fact-label" width="35%" valign="top" style="width:35%;padding:14px 10px 14px 0;border-bottom:1px solid #e3e0da;color:#77736d;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1px;line-height:14px;">ERROR CODE</td>
                                    <td class="fact-value break-word" width="65%" valign="top" style="width:65%;padding:14px 0;border-bottom:1px solid #e3e0da;color:#20201e;font-family:'Courier New',Courier,monospace;font-size:12px;line-height:18px;">{{ $payload['error_code'] ?? 'n/a' }}</td>
                                </tr>
                                <tr>
                                    <td class="fact-label" width="35%" valign="top" style="width:35%;padding:14px 10px 14px 0;border-bottom:1px solid #e3e0da;color:#77736d;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1px;line-height:14px;">EXCEPTION TYPE</td>
                                    <td class="fact-value break-word" width="65%" valign="top" style="width:65%;padding:14px 0;border-bottom:1px solid #e3e0da;color:#20201e;font-size:13px;font-weight:bold;line-height:19px;">{{ $payload['type'] ?? 'n/a' }}</td>
                                </tr>
                                <tr>
                                    <td class="fact-label" width="35%" valign="top" style="width:35%;padding:14px 10px 14px 0;border-bottom:1px solid #e3e0da;color:#77736d;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1px;line-height:14px;">OPERATION</td>
                                    <td class="fact-value break-word" width="65%" valign="top" style="width:65%;padding:14px 0;border-bottom:1px solid #e3e0da;color:#20201e;font-size:13px;line-height:19px;">{{ $payload['operation'] ?? 'n/a' }}</td>
                                </tr>
                                <tr>
                                    <td class="fact-label fact-label-last" width="35%" valign="top" style="width:35%;padding:14px 10px 14px 0;color:#77736d;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1px;line-height:14px;">OCCURRED AT</td>
                                    <td class="fact-value fact-value-last break-word" width="65%" valign="top" style="width:65%;padding:14px 0;color:#20201e;font-family:'Courier New',Courier,monospace;font-size:12px;line-height:18px;">{{ $payload['occurred_at'] ?? 'n/a' }}</td>
                                </tr>
                            </table>

                            <div style="margin-top:25px;padding:18px 18px 19px;border-left:5px solid #b83a2f;background-color:#f6f3ee;">
                                <div style="color:#77736d;font-family:'Courier New',Courier,monospace;font-size:9px;font-weight:bold;letter-spacing:1.2px;line-height:14px;">SANITIZED ERROR DETAIL</div>
                                <div class="break-word" style="margin-top:8px;color:#20201e;font-family:'Courier New',Courier,monospace;font-size:12px;line-height:19px;">{{ $payload['detail'] ?? 'No exception message was available.' }}</div>
                            </div>

                            <div style="margin-top:26px;padding-top:20px;border-top:1px solid #d9d5ce;">
                                <div style="color:#20201e;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:24px;">Trace safely in application logs</div>
                                <p style="margin:7px 0 0;color:#65625d;font-size:12px;line-height:19px;">Use the correlation ID to locate the complete server-side context. Stack traces, request bodies, SQL, tokens, and credentials are intentionally excluded.</p>
                                <div class="break-word" style="margin-top:12px;color:#b83a2f;font-family:'Courier New',Courier,monospace;font-size:11px;font-weight:bold;line-height:17px;">CORRELATION / {{ $payload['correlation_id'] ?? 'n/a' }}</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="footer" style="padding:19px 22px 21px;background-color:#20201e;color:#aaa69f;font-family:'Courier New',Courier,monospace;font-size:9px;letter-spacing:.5px;line-height:15px;">
                            AUTOMATED NOTICE FROM {{ strtoupper((string) ($payload['service'] ?? 'YOUR APPLICATION')) }}<br>
                            enggarasmoro/laravel-error-alert · please do not reply
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
