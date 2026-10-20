@component('mail::message')
# {{ $notification->title }}

{{ $notification->body }}

@if ($notification->action_url)
[{{ __('Open in :app', ['app' => config('dukavo.name')]) }}]({{ rtrim(config('app.url'), '/') }}/{{ ltrim($notification->action_url, '/') }})
@endif

{{ __('You are receiving this because of your notification settings for :app.', ['app' => config('dukavo.name')]) }}
@endcomponent
