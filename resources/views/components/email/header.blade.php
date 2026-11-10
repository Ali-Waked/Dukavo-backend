@props(['locale' => 'en'])

@php
    $alignment = $locale === 'ar' ? 'right' : 'left';
    $logoUrl = config('dukavo.email.logo_urls.'.($locale === 'ar' ? 'ar' : 'en'))
        ?: config('dukavo.email.logo_url');
@endphp

<tr>
    <td style="background-color:#0F766E;padding:26px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td align="{{ $alignment }}" style="font-family:'Segoe UI',Arial,sans-serif;">
                    <img
                        src="{{ $logoUrl }}"
                        width="{{ config('dukavo.email.logo_width', 168) }}"
                        alt="{{ __('email.brand.name') }}"
                        style="display:block;border:0;outline:none;text-decoration:none;height:auto;max-width:100%;"
                    >
                </td>
            </tr>
            <tr>
                <td align="{{ $alignment }}" style="padding-top:14px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.5;color:#CCFBF1;">
                    {{ __('email.brand.tagline') }}
                </td>
            </tr>
        </table>
    </td>
</tr>
<tr>
    <td style="height:4px;line-height:4px;font-size:0;background-color:#A3E635;">&nbsp;</td>
</tr>
