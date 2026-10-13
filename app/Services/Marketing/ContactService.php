<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\ContactStatus;
use App\Http\Requests\Marketing\StoreContactMessageRequest;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;

/**
 * Public contact form submissions.
 *
 * Two outcomes are kept strictly separate: the visitor's message is stored
 * first and is never lost, while the email notification is a best-effort
 * convenience layered on top. Sending is synchronous on purpose — with no
 * queue worker in the deployment story, dispatching to the database queue
 * would silently drop every notification. A transport failure is reported
 * (so it lands in the log/handler) but never surfaces to the visitor, because
 * the row in `contact_messages` *is* their message reaching the platform.
 */
class ContactService
{
    /**
     * Store the message and notify the platform inbox. `$attributes` is
     * validated, already-normalised input from
     * {@see StoreContactMessageRequest}.
     */
    public function submit(array $attributes): ContactMessage
    {
        $message = ContactMessage::query()->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'subject' => $attributes['subject'],
            'message' => $attributes['message'],
            'status' => ContactStatus::New,
        ]);

        try {
            Mail::to($this->recipient())->send(new ContactMessageReceived($message));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $message;
    }

    private function recipient(): string
    {
        return config('dukavo.marketing.contact_recipient')
            ?: (string) config('mail.from.address');
    }
}
