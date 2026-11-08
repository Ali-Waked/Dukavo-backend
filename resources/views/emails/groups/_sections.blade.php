@php
    $dir = $content['dir'] ?? 'ltr';
    $textAlign = $dir === 'rtl' ? 'right' : 'left';
@endphp

@if (! empty($content['summary']))
    <x-email.card :title="$content['summary_title'] ?? null">
        <x-email.summary-table :rows="$content['summary']" :locale="$content['locale']" />
    </x-email.card>
@endif

@foreach ($content['tables'] ?? [] as $table)
    <x-email.card :title="$table['title'] ?? null">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.5;color:#334155;border-collapse:collapse;">
            <tr>
                @foreach ($table['columns'] as $column)
                    <td style="padding:7px 4px;border-bottom:2px solid #E2E8F0;color:#475569;font-weight:700;text-align:{{ $textAlign }};">
                        {{ $column }}
                    </td>
                @endforeach
            </tr>
            @foreach ($table['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td style="padding:8px 4px;border-bottom:1px solid #F1F5F9;text-align:{{ $textAlign }};vertical-align:top;">
                            {{ $cell }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </x-email.card>
@endforeach

@foreach ($content['notes'] ?? [] as $note)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;background-color:#F8FAFC;border-radius:8px;border-{{ $dir === 'rtl' ? 'right' : 'left' }}:3px solid #A3E635;">
        <tr>
            <td style="padding:12px 14px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;line-height:1.6;color:#475569;text-align:{{ $textAlign }};">
                {{ $note }}
            </td>
        </tr>
    </table>
@endforeach
