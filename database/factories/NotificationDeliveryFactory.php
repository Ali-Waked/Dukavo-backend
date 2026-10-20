<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'event_key' => 'test.notification',
            'channel' => NotificationChannel::Mail,
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'recipient_ref' => 'u•••@example.test',
            'status' => NotificationDeliveryStatus::Pending,
            'attempts' => 0,
        ];
    }
}
