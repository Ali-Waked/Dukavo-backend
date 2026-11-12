<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Enums\MessageType;
use App\Models\ChannelConnection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Ai\Contracts\TextToSpeechProvider;
use App\Services\Ai\Data\SpeechSynthesis;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Providers\Tts\KokoroTtsProvider;
use App\Services\Ai\Providers\Tts\OpenAiTtsProvider;
use App\Services\Ai\Providers\Tts\PiperTtsProvider;
use App\Services\Ai\Providers\Tts\TtsProviderFactory;
use App\Services\Voice\VoiceReplyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Voice replies.
 *
 * The outbound voice loop must be free-first and explicit: a self-hosted engine
 * is the default, a paid engine is unreachable without an opt-in, and a written
 * answer becomes one audio message rather than a text copy nobody received.
 */
class VoiceReplyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ChannelConnection $connection;

    private Conversation $conversation;

    private object $fakeProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();

        $this->fakeProvider = new class implements TextToSpeechProvider
        {
            /** @var list<string> */
            public array $spoken = [];

            public function provider(): string
            {
                return 'fake-tts';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function isPaid(): bool
            {
                return false;
            }

            public function synthesize(string $text, ?string $voice = null, ?string $format = null): SpeechSynthesis
            {
                $this->spoken[] = $text;

                return new SpeechSynthesis(
                    contents: 'FAKE-AUDIO-BYTES',
                    mimeType: 'audio/ogg',
                    filename: 'reply-test.ogg',
                    format: 'ogg',
                    voice: 'fake',
                    model: 'fake-tts',
                    latencyMs: 12,
                );
            }
        };

        $this->app->instance(TextToSpeechProvider::class, $this->fakeProvider);

        [$this->connection, $this->conversation] = $this->withinTenant($this->tenant, function (): array {
            $connection = ChannelConnection::query()->create([
                'channel' => 'telegram',
                'provider' => 'telegram_bot',
                'name' => 'Support bot',
                'credentials' => ['bot_token' => '123:abc'],
                'status' => ChannelConnectionStatus::Connected,
                'health' => ChannelHealth::Healthy,
            ]);

            $customer = Customer::query()->create([
                'name' => 'Nadia Haddad',
                'status' => 'active',
            ]);

            $conversation = Conversation::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'customer_id' => $customer->getKey(),
                'channel' => 'telegram',
                'channel_account' => '777',
            ]);

            return [$connection, $conversation];
        });
    }

    public function test_a_written_reply_is_sent_as_one_audio_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);

        $message = $this->withinTenant($this->tenant, fn (): ConversationMessage => app(VoiceReplyService::class)->reply(
            $this->connection,
            $this->conversation,
            'Your order is on its way.',
        ));

        $this->assertSame(MessageType::Audio, $message->type);
        $this->assertSame('4242', $message->external_id);
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame(['Your order is on its way.'], $this->fakeProvider->spoken);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/sendVoice'));

        $this->assertDatabaseHas('ai_usage', [
            'tenant_id' => $this->tenant->getKey(),
            'feature' => 'voice_reply',
            'provider' => 'fake-tts',
        ]);
    }

    public function test_an_empty_answer_is_refused_before_the_engine_runs(): void
    {
        Http::fake();

        try {
            $this->withinTenant($this->tenant, fn () => app(VoiceReplyService::class)->reply(
                $this->connection,
                $this->conversation,
                '   ',
            ));

            $this->fail('An empty answer must not reach the speech engine.');
        } catch (AiRequestFailed) {
            // Expected: the text is validated before synthesis.
        }

        $this->assertSame([], $this->fakeProvider->spoken);
        Http::assertNothingSent();
    }

    public function test_a_channel_that_cannot_carry_audio_is_not_offered_a_voice_reply(): void
    {
        $service = app(VoiceReplyService::class);

        $this->assertTrue($service->supports($this->connection));

        $email = $this->withinTenant($this->tenant, fn (): ChannelConnection => ChannelConnection::query()->create([
            'channel' => 'email',
            'provider' => 'smtp',
            'name' => 'Support email',
            'credentials' => ['from_address' => 'shop@example.com'],
            'status' => ChannelConnectionStatus::Connected,
            'health' => ChannelHealth::Healthy,
        ]));

        $this->assertFalse($service->supports($email));
    }

    public function test_the_factory_defaults_to_a_free_self_hosted_engine(): void
    {
        $provider = TtsProviderFactory::fromConfig([
            'provider' => 'piper',
            'allow_paid' => false,
            'providers' => ['piper' => ['base_url' => 'http://piper.local']],
        ]);

        $this->assertInstanceOf(PiperTtsProvider::class, $provider);
        $this->assertFalse($provider->isPaid());
        $this->assertTrue($provider->isConfigured());

        $kokoro = TtsProviderFactory::fromConfig([
            'provider' => 'kokoro',
            'providers' => ['kokoro' => ['base_url' => 'http://kokoro.local']],
        ]);

        $this->assertInstanceOf(KokoroTtsProvider::class, $kokoro);
        $this->assertTrue($kokoro->isConfigured());
    }

    public function test_a_paid_engine_stays_unconfigured_without_an_explicit_opt_in(): void
    {
        $blocked = TtsProviderFactory::fromConfig([
            'provider' => 'openai',
            'allow_paid' => false,
            'providers' => ['openai' => [
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'sk-present-but-not-authorised',
            ]],
        ]);

        $this->assertInstanceOf(OpenAiTtsProvider::class, $blocked);
        $this->assertTrue($blocked->isPaid());
        $this->assertFalse($blocked->isConfigured());

        $allowed = TtsProviderFactory::fromConfig([
            'provider' => 'openai',
            'allow_paid' => true,
            'providers' => ['openai' => [
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'sk-authorised',
            ]],
        ]);

        $this->assertTrue($allowed->isConfigured());
    }

    public function test_an_unknown_provider_name_falls_back_to_the_free_engine(): void
    {
        $provider = TtsProviderFactory::fromConfig([
            'provider' => 'made-up-engine',
            'providers' => ['made-up-engine' => ['base_url' => 'http://example.test']],
        ]);

        $this->assertInstanceOf(PiperTtsProvider::class, $provider);
    }

    public function test_the_piper_adapter_returns_real_audio_bytes(): void
    {
        Http::fake([
            'http://piper.local/api/tts' => Http::response('RIFF-audio-bytes', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $provider = new PiperTtsProvider([
            'enabled' => true,
            'base_url' => 'http://piper.local',
            'path' => '/api/tts',
            'voice' => 'en_US-lessac-medium',
            'format' => 'wav',
        ]);

        $result = $provider->synthesize('Hello there.');

        $this->assertSame('RIFF-audio-bytes', $result->contents);
        $this->assertSame('audio/wav', $result->mimeType);
        $this->assertStringEndsWith('.wav', $result->filename);
        $this->assertGreaterThan(0, $result->size());
    }
}
