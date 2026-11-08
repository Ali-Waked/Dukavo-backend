@props([
    'locale' => 'en',
    'preferencesUrl' => null,
    'unsubscribeUrl' => null,
    'showPreferences' => false,
    'showUnsubscribe' => false,
    'securityNote' => null,
])

@php
    $alignment = $locale === 'ar' ? 'right' : 'left';
    $linkStyle = "color:#A3E635;text-decoration:underline;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;";
@endphp

<tr>
    <td style="background-color:#115E59;padding:28px 32px;font-family:'Segoe UI',Arial,sans-serif;">
        <p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#CCFBF1;text-align:{{ $alignment }};">
            {{ __('email.brand.footer_note') }}
        </p>

        @if ($securityNote)
            <p style="margin:0 0 12px;font-size:12px;line-height:1.6;color:#A7F3D0;text-align:{{ $alignment }};">
                {{ $securityNote }}
            </p>
        @endif

        <p style="margin:0 0 12px;font-size:13px;line-height:1.6;text-align:{{ $alignment }};">
            <a href="mailto:{{ config('dukavo.email.support_email') }}" style="{{ $linkStyle }}">
                {{ __('email.brand.help') }}
            </a>
        </p>

        @if ($showPreferences && $preferencesUrl)
            <p style="margin:0 0 8px;font-size:13px;line-height:1.6;text-align:{{ $alignment }};">
                <a href="{{ $preferencesUrl }}" style="{{ $linkStyle }}">{{ __('email.brand.preferences') }}</a>
            </p>
        @endif

        @if ($showUnsubscribe && $unsubscribeUrl)
            <p style="margin:0 0 8px;font-size:13px;line-height:1.6;text-align:{{ $alignment }};">
                <a href="{{ $unsubscribeUrl }}" style="{{ $linkStyle }}">{{ __('email.brand.unsubscribe') }}</a>
            </p>
        @endif

        <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#A7F3D0;text-align:{{ $alignment }};">
            &copy; {{ date('Y') }} {{ __('email.brand.name') }}. {{ __('email.brand.rights') }}
        </p>
    </td>
</tr>
