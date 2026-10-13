<x-mail::message>
# New contact message

<x-mail::table>
| Field | Value |
|-------|-------|
| Name | {{ $message->name }} |
| Email | {{ $message->email }} |
| Subject | {{ $message->subject }} |
| Received | {{ $message->created_at->format('Y-m-d H:i') }} UTC |
</x-mail::table>

{!! nl2br(e($message->message)) !!}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
