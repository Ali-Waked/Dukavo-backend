@props(['url', 'label', 'tone' => 'primary', 'locale' => 'en'])

@php
    $alignment = $locale === 'ar' ? 'right' : 'center';
    $background = $tone === 'dark' ? '#115E59' : '#0F766E';
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0;">
    <tr>
        <td align="{{ $alignment }}" style="border-radius:10px;background-color:{{ $background }};">
            <a
                href="{{ $url }}"
                style="display:inline-block;padding:13px 26px;border-radius:10px;background-color:{{ $background }};color:#FFFFFF;font-family:'Segoe UI',Arial,sans-serif;font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;"
            >{{ $label }}</a>
        </td>
    </tr>
</table>
