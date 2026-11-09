<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push;

use App\Enums\NotificationPriority;
use App\Models\NotificationDevice;
use App\Services\Notifications\Push\Contracts\PushTransport;
use JsonException;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Firebase Cloud Messaging over the HTTP v1 API.
 *
 * The official Admin SDK signs the OAuth assertion and talks HTTP v1; no
 * deprecated legacy endpoint is used. Credentials are read once per worker
 * process from configuration, never from a request payload, and are never
 * returned by an endpoint. FCM only transports a message the database already
 * holds.
 */
final class FirebasePushTransport implements PushTransport
{
    private ?Messaging $messaging = null;

    public function isConfigured(): bool
    {
        return (bool) config('dukavo.notifications.channels.push.enabled', false)
            && filled(config('firebase.project_id'))
            && $this->credentials() !== null;
    }

    public function send(
        NotificationDevice $device,
        string $title,
        string $body,
        array $data,
        string $category,
        NotificationPriority $priority,
    ): string {
        if (! $this->isConfigured()) {
            throw PushDeliveryException::temporary('push_not_configured');
        }

        $channel = (string) config('firebase.android_channels.'.$category, config('firebase.android_channels.default'));
        $urgent = $priority->isUrgent();

        try {
            $message = CloudMessage::new()
                ->withToken($device->token)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map(
                    static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                    $data,
                ))
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => $urgent ? 'high' : 'normal',
                    'notification' => [
                        'channel_id' => $channel,
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                    'ttl' => $urgent ? '3600s' : '86400s',
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority' => $urgent ? '10' : '5',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => ['title' => $title, 'body' => $body],
                            'sound' => 'default',
                            'badge' => 1,
                            'category' => $category,
                        ],
                    ],
                ]));

            // HTTP v1 returns the message name, e.g.
            // `projects/{project}/messages/{id}`. It is stored as the provider
            // reference so an operator can quote it to Firebase support.
            $response = $this->messaging()->send($message);

            return (string) ($response['name'] ?? '');
        } catch (NotFound|InvalidArgument $exception) {
            throw PushDeliveryException::permanent($exception->getMessage(), $exception);
        } catch (MessagingException $exception) {
            $reason = $exception->getMessage();

            // Firebase reports an unregistered token as a 404 or an
            // INVALID_ARGUMENT in some SDK versions; both mean "stop using it".
            if (str_contains($reason, 'UNREGISTERED')
                || str_contains($reason, 'not found')
                || str_contains($reason, 'invalid registration')) {
                throw PushDeliveryException::permanent($reason, $exception);
            }

            throw PushDeliveryException::temporary($reason, $exception);
        } catch (\Throwable $exception) {
            throw PushDeliveryException::temporary($exception->getMessage(), $exception);
        }
    }

    private function messaging(): Messaging
    {
        if ($this->messaging instanceof Messaging) {
            return $this->messaging;
        }

        $credentials = $this->credentials();

        if ($credentials === null) {
            throw PushDeliveryException::temporary('push_credentials_missing');
        }

        $factory = (new Factory)->withServiceAccount($credentials);

        if (filled(config('firebase.project_id'))) {
            $factory = $factory->withProjectId((string) config('firebase.project_id'));
        }

        $timeout = (float) config('firebase.timeout', 10);

        return $this->messaging = $factory
            ->withHttpClientOptions(
                HttpClientOptions::default()
                    ->withConnectTimeout($timeout)
                    ->withReadTimeout($timeout),
            )
            ->createMessaging();
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function credentials(): array|string|null
    {
        $path = config('firebase.credentials');

        if (filled($path) && is_file((string) $path)) {
            return (string) $path;
        }

        $raw = config('firebase.credentials_json');

        if (blank($raw)) {
            return null;
        }

        $raw = trim((string) $raw);
        $decoded = base64_decode($raw, true);

        if ($decoded !== false && str_contains($decoded, 'project_id')) {
            $raw = $decoded;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
