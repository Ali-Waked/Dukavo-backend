@props(['title' => null])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;">
    <tr>
        <td style="padding:16px 18px;font-family:'Segoe UI',Arial,sans-serif;">
            @if ($title)
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#64748B;">
                    {{ $title }}
                </p>
            @endif
            <div style="font-size:14px;line-height:1.6;color:#334155;">
                {{ $slot }}
            </div>
        </td>
    </tr>
</table>
