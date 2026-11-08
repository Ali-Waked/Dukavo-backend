{{ __('email.brand.name') }}

{{ $content['heading'] }}
{{ $content['intro'] ?? '' }}

@if (! empty($content['summary']))
{{ $content['summary_title'] ?? '' }}
@foreach ($content['summary'] as $row)
{{ $row['label'] ?? '' }}: {{ $row['value'] ?? '' }}
@endforeach
@endif

@foreach ($content['tables'] ?? [] as $table)
{{ $table['title'] ?? '' }}
@foreach ($table['rows'] as $row)
- {{ implode(' | ', $row) }}
@endforeach
@endforeach

@foreach ($content['notes'] ?? [] as $note)
{{ $note }}
@endforeach

@if (! empty($content['cta']))
{{ $content['cta']['label'] }}: {{ $content['cta']['url'] }}
@endif

@if (! empty($content['security_note']))
{{ $content['security_note'] }}
@endif

--
{{ __('email.brand.footer_note') }}
{{ __('email.brand.help') }}: {{ config('dukavo.email.support_email') }}
@if (! empty($content['show_preferences']) && ! empty($content['preferences_url']))
{{ __('email.brand.preferences') }}: {{ $content['preferences_url'] }}
@endif
@if (! empty($content['show_unsubscribe']) && ! empty($content['unsubscribe_url']))
{{ __('email.brand.unsubscribe') }}: {{ $content['unsubscribe_url'] }}
@endif

{{ __('email.brand.automatic') }}
