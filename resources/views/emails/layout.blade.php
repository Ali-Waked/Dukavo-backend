<!DOCTYPE html>
<html lang="{{ $content['locale'] }}" dir="{{ $content['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <title>{{ $content['heading'] }}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;">
        {{ $content['preheader'] ?? '' }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F1F5F9;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;">
                    <x-email.header :locale="$content['locale']" />

                    <tr>
                        <td style="padding:32px;font-family:'Segoe UI',Arial,sans-serif;">
                            @if (! empty($content['status']))
                                <div style="margin-bottom:14px;text-align:{{ $content['dir'] === 'rtl' ? 'right' : 'left' }};">
                                    <x-email.status-badge :label="$content['status']['label']" :tone="$content['status']['tone']" />
                                </div>
                            @endif

                            @if (! empty($content['eyebrow']))
                                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#0F766E;text-align:{{ $content['dir'] === 'rtl' ? 'right' : 'left' }};">
                                    {{ $content['eyebrow'] }}
                                </p>
                            @endif

                            <h1 style="margin:0 0 12px;font-size:24px;line-height:1.3;font-weight:700;color:#0F172A;text-align:{{ $content['dir'] === 'rtl' ? 'right' : 'left' }};">
                                {{ $content['heading'] }}
                            </h1>

                            @if (! empty($content['intro']))
                                <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#475569;text-align:{{ $content['dir'] === 'rtl' ? 'right' : 'left' }};">
                                    {!! nl2br(e($content['intro'])) !!}
                                </p>
                            @endif

                            @include('emails.groups.'.$content['group'], ['content' => $content])

                            @if (! empty($content['cta']))
                                <x-email.call-to-action
                                    :url="$content['cta']['url']"
                                    :label="$content['cta']['label']"
                                    :note="$content['cta']['note'] ?? null"
                                    :locale="$content['locale']"
                                    :tone="$content['cta']['tone'] ?? 'primary'"
                                />
                            @endif
                        </td>
                    </tr>

                    <x-email.footer
                        :locale="$content['locale']"
                        :preferences-url="$content['preferences_url'] ?? null"
                        :unsubscribe-url="$content['unsubscribe_url'] ?? null"
                        :show-preferences="$content['show_preferences'] ?? false"
                        :show-unsubscribe="$content['show_unsubscribe'] ?? false"
                        :security-note="$content['security_note'] ?? null"
                    />
                </table>

                <p style="margin:16px 0 0;font-family:'Segoe UI',Arial,sans-serif;font-size:11px;line-height:1.6;color:#94A3B8;text-align:center;max-width:600px;">
                    {{ __('email.brand.automatic') }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
