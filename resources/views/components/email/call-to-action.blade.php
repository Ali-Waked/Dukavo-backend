@props(['url', 'label', 'note' => null, 'locale' => 'en', 'tone' => 'primary'])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;">
    <tr>
        <td align="{{ $locale === 'ar' ? 'right' : 'center' }}" style="font-family:'Segoe UI',Arial,sans-serif;">
            <x-email.button :url="$url" :label="$label" :tone="$tone" :locale="$locale" />
            @if ($note)
                <p style="margin:10px 0 0;font-size:12px;line-height:1.6;color:#64748B;text-align:{{ $locale === 'ar' ? 'right' : 'center' }};">
                    {{ $note }}
                </p>
            @endif
        </td>
    </tr>
</table>
