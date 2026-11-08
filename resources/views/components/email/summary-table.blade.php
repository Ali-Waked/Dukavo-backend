@props(['rows' => [], 'columns' => [], 'locale' => 'en'])

@php
    $alignment = $locale === 'ar' ? 'right' : 'left';
@endphp

@if ($rows !== [])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.5;color:#334155;">
        @foreach ($rows as $row)
            <tr>
                <td style="padding:7px 0;color:#64748B;text-align:{{ $alignment }};vertical-align:top;width:44%;">
                    {{ $row['label'] ?? '' }}
                </td>
                <td style="padding:7px 0;color:#0F172A;text-align:{{ $locale === 'ar' ? 'left' : 'right' }};font-weight:600;vertical-align:top;">
                    {{ $row['value'] ?? '' }}
                </td>
            </tr>
        @endforeach
    </table>
@endif

@if ($columns !== [] && $rows === [])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.5;color:#334155;">
        <tr>
            @foreach ($columns as $column)
                <td style="padding:6px 4px;border-bottom:1px solid #E2E8F0;color:#64748B;font-weight:700;text-align:{{ $alignment }};">
                    {{ $column }}
                </td>
            @endforeach
        </tr>
    </table>
@endif
