<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\VoiceMessageStatus;
use App\Jobs\ProcessVoiceMessage;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoiceMessage;
use App\Models\WhatsappAccount;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Contracts\SpeechToTextProvider;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Voice\VoiceMediaRetention;
use App\Services\Voice\VoiceMessageProcessor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\Support\FakeSpeechToTextProvider;
use Tests\TestCase;

/**
 * Voice notes are transcribed and proposed, never silently turned into orders.
 */
class VoiceCommerceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->store = $this->withinTenant(
            $this->tenant,
            fn (): Store => Store::query()->where('tenant_id', $this->tenant->getKey())->firstOrFail(),
        );

        config()->set('ai.enabled', true);
        config()->set('ai.features.voice_transcription.enabled', true);
        config()->set('ai.features.voice_transcription.daily_per_tenant', 100);
        config()->set('ai.features.voice_order.enabled', true);
        config()->set('ai.features.voice_order.daily_per_tenant', 100);
        config()->set('ai.features.voice_order.daily_tokens_per_tenant', 1000000);
        config()->set('ai.provider', 'fake');
        config()->set('ai.fallback_providers', []);
        config()->set('ai.transcription.provider', 'fake');
        config()->set('ai.providers.fake', [
            'driver' => 'fake',
            'base_url' => '',
            'api_key' => null,
            'path' => '',
            'model' => 'fake',
            'transport' => [],
        ]);
    }

    public function test_the_webhook_registers_an_audio_note_and_queues_it_without_calling_ai_inline(): void
    {
        Queue::fake();

        $account = WhatsappAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => 'Voice Demo',
            'phone_number_id' => '100200300',
            'business_account_id' => '900800700',
            'access_token' => 'meta-token-value',
            'status' => 'connected',
            'webhook_verify_token' => 'verify-me',
        ]);

        config()->set('services.whatsapp.app_secret', 'test-app-secret');

        Http::fake([
            'https://graph.facebook.com/v26.0/media-1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/voice.ogg',
                'mime_type' => 'audio/ogg',
            ]),
            'https://lookaside.fbsbx.com/*' => Http::response('fake-voice-bytes', 200, [
                'Content-Type' => 'audio/ogg',
                'Content-Disposition' => 'attachment; filename="voice.ogg"',
            ]),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '900800700',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'messages' => [[
                            'from' => '+15551239999',
                            'id' => 'wamid.VOICE',
                            'type' => 'audio',
                            'timestamp' => (string) now()->timestamp,
                            'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $signature = hash_hmac('sha256', json_encode($payload), 'test-app-secret');

        $this->withHeader('X-Hub-Signature-256', 'sha256='.$signature)
            ->postJson('/api/v1/webhooks/whatsapp', $payload)
            ->assertOk();

        $voice = VoiceMessage::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(VoiceMessageStatus::Queued, $voice->status);
        $this->assertNotNull($voice->media_path);
        Storage::disk('local')->assertExists($voice->media_path);
        Queue::assertPushed(ProcessVoiceMessage::class);
    }

    public function test_processing_transcribes_and_grounds_the_draft_without_creating_a_cart(): void
    {
        $product = $this->stockedProduct($this->tenant, $this->store, 20);
        [, , $voice] = $this->voice();

        $speech = new FakeSpeechToTextProvider;
        $this->app->instance(SpeechToTextProvider::class, $speech);
        $this->fakeModel($this->completion([
            'items' => [[
                'spoken_name' => $product['product']->name,
                'product_id' => $product['product']->getKey(),
                'variant_id' => $product['variant']->getKey(),
                'quantity' => 2,
                'notes' => null,
            ]],
        ]));

        $processed = $this->withinTenant(
            $this->tenant,
            fn (): VoiceMessage => app(VoiceMessageProcessor::class)->process($voice),
        );

        $this->assertSame(VoiceMessageStatus::Ready, $processed->status);
        $this->assertSame('أريد طلب قطعتين من المنتج.', $processed->transcript);
        $this->assertCount(1, $speech->calls);
        $this->assertSame(2, $processed->extraction['items'][0]['quantity']);
        $this->assertEquals(10.0, $processed->extraction['items'][0]['unit_price']);
        $this->assertTrue($processed->extraction['items'][0]['resolved']);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_ambiguous_variant_is_left_for_a_human_instead_of_guessed(): void
    {
        $product = $this->stockedProduct($this->tenant, $this->store, 20);

        $this->withinTenant($this->tenant, function () use ($product): void {
            $variant = ProductVariant::query()->create([
                'tenant_id' => $this->tenant->getKey(),
                'product_id' => $product['product']->getKey(),
                'name' => 'Large',
                'sku' => 'SKU-LARGE',
                'price' => 12.00,
                'is_default' => false,
            ]);

            $variant->inventories()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'product_id' => $product['product']->getKey(),
                'quantity' => 10,
            ]);
        });

        [, , $voice] = $this->voice();

        $this->app->instance(SpeechToTextProvider::class, new FakeSpeechToTextProvider);
        $this->fakeModel($this->completion([
            'items' => [[
                'spoken_name' => $product['product']->name,
                'product_id' => $product['product']->getKey(),
                'variant_id' => null,
                'quantity' => 1,
                'notes' => 'Customer did not name a size.',
            ]],
        ]));

        $processed = $this->withinTenant(
            $this->tenant,
            fn (): VoiceMessage => app(VoiceMessageProcessor::class)->process($voice),
        );

        $this->assertSame(VoiceMessageStatus::Ready, $processed->status);
        $this->assertSame([], $processed->extraction['items']);
        $this->assertSame('variant_required', $processed->extraction['unresolved'][0]['reason']);
        $this->assertContains('unresolved_items', $processed->extraction['warning_codes']);
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_applying_a_reviewed_voice_draft_creates_one_cart_and_is_idempotent(): void
    {
        $product = $this->stockedProduct($this->tenant, $this->store, 20);
        [$conversation, , $voice] = $this->voice([
            'status' => VoiceMessageStatus::Ready,
            'transcript' => 'طلبت قطعتين.',
            'extraction' => [
                'intent' => 'order',
                'items' => [['product_id' => $product['product']->getKey(), 'variant_id' => $product['variant']->getKey()]],
                'unresolved' => [],
            ],
            'processed_at' => now(),
        ]);

        $payload = [
            'confirmed' => true,
            'items' => [[
                'product_id' => $product['product']->getKey(),
                'product_variant_id' => $product['variant']->getKey(),
                'quantity' => 2,
            ]],
        ];

        $first = $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($this->voiceUrl($conversation, $voice).'/apply', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $cartId = (int) $first->json('data.id');

        $this->assertGreaterThan(0, $cartId);
        $this->assertSame(2, (int) $first->json('data.items.0.quantity'));
        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('orders', 0);

        $this->withinTenant($this->tenant, function () use ($voice, $cartId): void {
            $fresh = VoiceMessage::query()->findOrFail($voice->getKey());

            $this->assertSame(VoiceMessageStatus::Applied, $fresh->status);
            $this->assertSame($cartId, (int) $fresh->applied_cart_id);
            $this->assertSame($this->merchant->getKey(), (int) $fresh->applied_by);
        });

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($this->voiceUrl($conversation, $voice).'/apply', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $cartId);

        $this->assertDatabaseCount('carts', 1);
        $this->assertSame(2, (int) $this->withinTenant(
            $this->tenant,
            fn () => CartItem::query()->firstOrFail()->quantity,
        ));
    }

    public function test_applying_without_confirmation_is_refused(): void
    {
        $product = $this->stockedProduct($this->tenant, $this->store, 20);
        [$conversation, , $voice] = $this->voice([
            'status' => VoiceMessageStatus::Ready,
            'extraction' => ['intent' => 'order', 'items' => [], 'unresolved' => []],
        ]);

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($this->voiceUrl($conversation, $voice).'/apply', [
                'confirmed' => false,
                'items' => [[
                    'product_id' => $product['product']->getKey(),
                    'product_variant_id' => $product['variant']->getKey(),
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_retrying_a_failed_voice_note_queues_it_again_without_calling_ai_inline(): void
    {
        Queue::fake();
        [$conversation, , $voice] = $this->voice([
            'status' => VoiceMessageStatus::Failed,
            'failure_code' => 'ai_unavailable',
            'failure_message' => 'Temporary failure.',
        ]);

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($this->voiceUrl($conversation, $voice).'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessVoiceMessage::class);
    }

    public function test_the_api_exposes_the_voice_review_payload_on_the_message_and_show_endpoint(): void
    {
        $product = $this->stockedProduct($this->tenant, $this->store, 20);
        [$conversation, $message, $voice] = $this->voice([
            'status' => VoiceMessageStatus::Ready,
            'transcript' => 'أريد قطعتين.',
            'processed_at' => now(),
            'extraction' => [
                'intent' => 'order',
                'summary' => 'قطعتان من المنتج.',
                'detected_language' => 'arabic',
                'confidence' => 0.92,
                'currency' => 'SAR',
                'items' => [[
                    'requested_name' => $product['product']->name,
                    'product_id' => $product['product']->getKey(),
                    'variant_id' => $product['variant']->getKey(),
                    'quantity' => 2,
                    'notes' => '',
                    'resolved' => true,
                    'reason' => null,
                    'name' => $product['product']->name,
                    'sku' => $product['variant']->sku,
                    'unit_price' => 10.0,
                    'currency' => 'SAR',
                    'available' => 20,
                    'sufficient_stock' => true,
                    'line_total' => 20.0,
                ]],
                'unresolved' => [],
                'missing_information' => [],
                'warnings' => [],
                'warning_codes' => [],
                'transcript' => 'أريد قطعتين.',
            ],
        ]);

        $messages = $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(sprintf('/api/v1/tenant/conversations/%d/messages', $conversation->getKey()))
            ->assertOk();

        $row = collect($messages->json('data'))->firstWhere('id', $message->getKey());

        $this->assertNotNull($row);
        $this->assertSame($voice->getKey(), $row['voice']['id']);
        $this->assertSame('ready', $row['voice']['status']);
        $this->assertSame('أريد قطعتين.', $row['voice']['transcript']);
        $this->assertTrue($row['voice']['capabilities']['apply']);
        $this->assertSame(
            sprintf(
                '/tenant/conversations/%d/messages/%d/media',
                $conversation->getKey(),
                $message->getKey(),
            ),
            $row['voice']['media']['url'],
        );

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson($this->voiceUrl($conversation, $voice))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.extraction.items.0.quantity', 2)
            ->assertJsonPath('data.capabilities.apply', true);
    }

    public function test_retention_removes_the_audio_but_keeps_the_transcript_and_review_record(): void
    {
        [, $message, $voice, $path] = $this->voice([
            'status' => VoiceMessageStatus::Ready,
            'transcript' => 'احتفظ بالنص.',
            'processed_at' => now(),
        ]);

        $this->withinTenant($this->tenant, function () use ($voice): void {
            VoiceMessage::query()->whereKey($voice->getKey())->update([
                'created_at' => now()->subDays(40),
                'updated_at' => now()->subDays(40),
            ]);
        });

        Storage::disk('local')->assertExists($path);

        $stats = app(VoiceMediaRetention::class)->prune(30);

        $this->assertSame(1, $stats['purged']);
        Storage::disk('local')->assertMissing($path);

        $this->withinTenant($this->tenant, function () use ($voice, $message): void {
            $fresh = VoiceMessage::query()->findOrFail($voice->getKey());
            $freshMessage = ConversationMessage::query()->findOrFail($message->getKey());

            $this->assertNull($fresh->media_path);
            $this->assertNotNull($fresh->media_purged_at);
            $this->assertSame('احتفظ بالنص.', $fresh->transcript);
            $this->assertArrayNotHasKey('path', $freshMessage->meta['media']);
        });
    }

    /**
     * @param  array<string, mixed>  $voiceOverrides
     * @return array{0: Conversation, 1: ConversationMessage, 2: VoiceMessage, 3: string}
     */
    private function voice(array $voiceOverrides = []): array
    {
        return $this->withinTenant($this->tenant, function () use ($voiceOverrides): array {
            $customer = Customer::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'name' => 'Voice Customer',
                'phone' => '+966500001111',
                'status' => 'active',
            ]);

            $conversation = Conversation::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'customer_id' => $customer->getKey(),
                'channel' => 'whatsapp',
            ]);

            $path = sprintf('whatsapp/%d/%d/%s.ogg', $this->tenant->getKey(), $conversation->getKey(), uniqid());
            Storage::disk('local')->put($path, 'fake-voice-bytes');

            $message = ConversationMessage::query()->create([
                'tenant_id' => $this->tenant->getKey(),
                'conversation_id' => $conversation->getKey(),
                'direction' => MessageDirection::Inbound,
                'type' => MessageType::Audio,
                'external_id' => 'wamid.'.uniqid(),
                'meta' => [
                    'provider' => 'meta',
                    'media' => [
                        'path' => $path,
                        'mime_type' => 'audio/ogg',
                        'size' => 16,
                        'filename' => 'voice.ogg',
                    ],
                ],
                'sent_at' => now(),
            ]);

            $voice = VoiceMessage::query()->create(array_merge([
                'tenant_id' => $this->tenant->getKey(),
                'conversation_id' => $conversation->getKey(),
                'conversation_message_id' => $message->getKey(),
                'status' => VoiceMessageStatus::Queued,
                'media_path' => $path,
                'media_mime_type' => 'audio/ogg',
                'media_size' => 16,
                'media_filename' => 'voice.ogg',
            ], $voiceOverrides));

            return [$conversation, $message, $voice, $path];
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function completion(array $overrides = []): AiCompletion
    {
        return new AiCompletion(
            text: '',
            structured: array_merge([
                'intent' => 'order',
                'summary' => 'العميل يطلب منتجًا من التسجيل الصوتي.',
                'detected_language' => 'arabic',
                'confidence' => 0.94,
                'items' => [],
                'missing_information' => [],
            ], $overrides),
            model: 'fake-model',
            inputTokens: 100,
            outputTokens: 40,
            totalTokens: 140,
        );
    }

    private function fakeModel(AiCompletion $completion): void
    {
        $model = new FakeLanguageModel($completion);

        $this->app->instance(LanguageModel::class, $model);
        $this->app->instance(AiProviderFactory::class, new FakeAiProviderFactory($model));
    }

    private function voiceUrl(Conversation $conversation, VoiceMessage $voice): string
    {
        return sprintf(
            '/api/v1/tenant/conversations/%d/voice-messages/%d',
            $conversation->getKey(),
            $voice->getKey(),
        );
    }
}
