<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\ContactStatus;
use App\Http\Requests\Marketing\StoreContactMessageRequest;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use Illuminate\Support\Facades\Mail;

/**
 * Public contact form submissions.
 *
 * Two outcomes are kept strictly separate: the visitor's message is stored
 * first and is never lost, while the notifications on top are best-effort
 * conveniences. The platform alert is raised through the dispatcher so it
 * reaches the operations queue in-app (and by email when configured); the
 * mailbox delivery is a separate, deliberately synchronous fallback — with no
 * queue worker guaranteed in the deployment story, dispatching to the database
 * queue would silently drop it. A transport failure is reported but never
 * surfaces to the visitor, because the row in `contact_messages` *is* their
 * message reaching the platform.
 */
class ContactService
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

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

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::PLATFORM_CONTACT_RECEIVED,
            dedupeKey: 'platform.contact.received:'.$message->getKey(),
            data: [
                'name' => $message->name,
                'subject' => $message->subject,
            ],
            actionUrl: '/contact-messages',
        ));

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
