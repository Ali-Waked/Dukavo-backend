<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Services\Channels\Data\ChannelCapabilities;

/**
 * The capability and configuration matrix for every channel Dukavo knows.
 *
 * This is a declaration, not a guarantee: a channel listed as available still
 * needs the platform owner (and often the merchant) to supply credentials. A
 * channel with `availability = awaiting_provider_access` is one whose official
 * API access has not been granted, and the UI must say so rather than pretend
 * it works.
 */
final class ChannelCapabilityRegistry
{
    public const AVAILABLE = 'available';

    public const AWAITING_CONFIGURATION = 'awaiting_configuration';

    public const AWAITING_PROVIDER_ACCESS = 'awaiting_provider_access';

    /**
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     provider: string,
     *     availability: string,
     *     capabilities: ChannelCapabilities,
     *     credential_fields: list<array{key: string, label: string, secret: bool, required: bool, help: string}>,
     *     notes: string,
     * }>
     */
    public static function all(): array
    {
        $channels = [];

        foreach (self::definitions() as $definition) {
            $channels[$definition['key']] = $definition;
        }

        return $channels;
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     provider: string,
     *     availability: string,
     *     capabilities: ChannelCapabilities,
     *     credential_fields: list<array{key: string, label: string, secret: bool, required: bool, help: string}>,
     *     notes: string,
     * }|null
     */
    public static function get(string $channel): ?array
    {
        return self::all()[$channel] ?? null;
    }

    public static function capabilities(string $channel): ChannelCapabilities
    {
        return self::get($channel)['capabilities'] ?? new ChannelCapabilities;
    }

    public static function exists(string $channel): bool
    {
        return self::get($channel) !== null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     provider: string,
     *     availability: string,
     *     capabilities: ChannelCapabilities,
     *     credential_fields: list<array{key: string, label: string, secret: bool, required: bool, help: string}>,
     *     notes: string,
     * }>
     */
    private static function definitions(): array
    {
        return [
            [
                'key' => 'whatsapp',
                'name' => 'WhatsApp Business',
                'provider' => 'meta_cloud',
                'availability' => self::AVAILABLE,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: true, audio: true, voice: true,
                    video: true, template: true, deliveryStatus: true, readStatus: true,
                    mediaDownload: true,
                ),
                'credential_fields' => [
                    ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'secret' => false, 'required' => true, 'help' => 'Meta WhatsApp Business phone number id.'],
                    ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true, 'help' => 'System user access token with whatsapp_business_messaging.'],
                    ['key' => 'business_account_id', 'label' => 'WhatsApp Business Account ID', 'secret' => false, 'required' => false, 'help' => 'WABA id used for template management.'],
                    ['key' => 'webhook_verify_token', 'label' => 'Webhook verify token', 'secret' => true, 'required' => false, 'help' => 'Token Meta echoes during webhook verification.'],
                ],
                'notes' => 'Meta Cloud API. Free-form replies are limited to 24 hours after the customer message; templates are required afterwards.',
            ],
            [
                'key' => 'instagram',
                'name' => 'Instagram Messaging',
                'provider' => 'meta_messaging',
                'availability' => self::AVAILABLE,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: false, audio: true, voice: false,
                    video: true, template: false, deliveryStatus: true, readStatus: true,
                    mediaDownload: true,
                ),
                'credential_fields' => [
                    ['key' => 'page_id', 'label' => 'Instagram professional account ID', 'secret' => false, 'required' => true, 'help' => 'The Instagram professional account id linked to the Meta app.'],
                    ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true, 'help' => 'Page access token with instagram_basic and instagram_manage_messages.'],
                ],
                'notes' => 'Requires a Meta app with Instagram Messaging permissions and App Review. Uses the same webhook signature as Messenger.',
            ],
            [
                'key' => 'messenger',
                'name' => 'Facebook Messenger',
                'provider' => 'meta_messaging',
                'availability' => self::AVAILABLE,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: true, audio: true, voice: false,
                    video: true, template: false, deliveryStatus: true, readStatus: true,
                    mediaDownload: true,
                ),
                'credential_fields' => [
                    ['key' => 'page_id', 'label' => 'Facebook Page ID', 'secret' => false, 'required' => true, 'help' => 'The Facebook Page id the app is subscribed to.'],
                    ['key' => 'access_token', 'label' => 'Page access token', 'secret' => true, 'required' => true, 'help' => 'Page access token with pages_messaging.'],
                ],
                'notes' => 'Requires a Meta app with pages_messaging and App Review. Uses the same webhook signature as Instagram.',
            ],
            [
                'key' => 'telegram',
                'name' => 'Telegram',
                'provider' => 'telegram_bot',
                'availability' => self::AVAILABLE,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: true, audio: true, voice: true,
                    video: true, template: false, deliveryStatus: true, readStatus: false,
                    mediaDownload: true, typing: true,
                ),
                'credential_fields' => [
                    ['key' => 'bot_token', 'label' => 'Bot token', 'secret' => true, 'required' => true, 'help' => 'Token from @BotFather.'],
                    ['key' => 'webhook_secret', 'label' => 'Webhook secret token', 'secret' => true, 'required' => false, 'help' => 'Secret sent in X-Telegram-Bot-Api-Secret-Token. Enter the value you register with setWebhook; if omitted, one is generated and only its hash is retained.'],
                ],
                'notes' => 'Telegram Bot API. Voice notes are supported natively; read receipts are not available to bots.',
            ],
            [
                'key' => 'tiktok',
                'name' => 'TikTok Business Messaging',
                'provider' => 'tiktok_business',
                'availability' => self::AWAITING_PROVIDER_ACCESS,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: false, document: false, audio: false, voice: false,
                    video: false, template: false, deliveryStatus: false, readStatus: false,
                    mediaDownload: false,
                ),
                'credential_fields' => [
                    ['key' => 'client_key', 'label' => 'Client key', 'secret' => false, 'required' => true, 'help' => 'TikTok developer app client key.'],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true, 'help' => 'TikTok developer app client secret.'],
                ],
                'notes' => 'Official Business Messaging access is granted per partner. The adapter is present but disabled until access is approved.',
            ],
            [
                'key' => 'email',
                'name' => 'Email',
                'provider' => 'smtp',
                'availability' => self::AVAILABLE,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: true, audio: false, voice: false,
                    video: false, template: true, deliveryStatus: true, readStatus: false,
                    mediaDownload: false,
                ),
                'credential_fields' => [
                    ['key' => 'from_address', 'label' => 'From address', 'secret' => false, 'required' => true, 'help' => 'Sender address for outbound mail.'],
                    ['key' => 'from_name', 'label' => 'From name', 'secret' => false, 'required' => false, 'help' => 'Display name for outbound mail.'],
                ],
                'notes' => 'Uses the platform mail transport. Delivery receipts arrive through the signed email webhook when the provider supports them.',
            ],
            [
                'key' => 'sms',
                'name' => 'SMS',
                'provider' => 'http_gateway',
                'availability' => self::AWAITING_CONFIGURATION,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: false, document: false, audio: false, voice: false,
                    video: false, template: false, deliveryStatus: true, readStatus: false,
                    mediaDownload: false,
                ),
                'credential_fields' => [
                    ['key' => 'endpoint', 'label' => 'Gateway endpoint', 'secret' => false, 'required' => true, 'help' => 'HTTPS endpoint of the SMS gateway.'],
                    ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'required' => true, 'help' => 'Credential for the SMS gateway.'],
                    ['key' => 'sender_id', 'label' => 'Sender ID', 'secret' => false, 'required' => false, 'help' => 'Registered sender identifier.'],
                ],
                'notes' => 'Generic HTTP SMS gateway. Configure a provider endpoint before enabling.',
            ],
            [
                'key' => 'x',
                'name' => 'X (Twitter)',
                'provider' => 'x_direct_message',
                'availability' => self::AWAITING_PROVIDER_ACCESS,
                'capabilities' => new ChannelCapabilities(
                    text: true, image: true, document: false, audio: false, voice: false,
                    video: false, template: false, deliveryStatus: true, readStatus: false,
                    mediaDownload: true,
                ),
                'credential_fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'required' => true, 'help' => 'X API key with Direct Message access.'],
                    ['key' => 'api_secret', 'label' => 'API secret', 'secret' => true, 'required' => true, 'help' => 'X API secret.'],
                ],
                'notes' => 'Direct Message access requires an elevated X API plan. The adapter is present but disabled until access is granted.',
            ],
        ];
    }
}
