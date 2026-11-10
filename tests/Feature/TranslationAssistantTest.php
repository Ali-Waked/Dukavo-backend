<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TranslationStatus;
use App\Models\AiUsage;
use App\Models\FaqEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\AiProviderFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * The AI translation assistant: generation, review and approval.
 *
 * No test here reaches a provider. The fake returns the structured answer a
 * real model would, which is enough to prove the properties that matter:
 * AI output is a proposal, only an explicit approval publishes it, a manual
 * edit and an approved copy are protected from silent regeneration, changing
 * the source marks rows stale without hiding the last approved copy, every
 * id is scoped to the current workspace, and the target set is the store's
 * enabled languages rather than a hardcoded pair.
 */
class TranslationAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->store->forceFill([
            'storefront_enabled' => true,
            'enabled_locales' => ['en', 'ar'],
        ])->save();

        $this->merchant = $this->memberOf($this->tenant, 'merchant');

        config()->set('ai.enabled', true);
        config()->set('ai.features.translation.enabled', true);
        config()->set('ai.features.translation.daily_per_user', 1000);
        config()->set('ai.features.translation.daily_per_tenant', 1000);
        config()->set('ai.features.translation.daily_tokens_per_user', 1000000);
        config()->set('ai.features.translation.daily_tokens_per_tenant', 1000000);
        config()->set('ai.features.translation.repair_attempts', 0);
    }

    private function api(User $user, ?Tenant $tenant = null): static
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant', ($tenant ?? $this->tenant)->slug);
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function fakeTranslation(array $translations, string $model = 'grok-4.7'): void
    {
        $completion = new AiCompletion(
            text: '',
            structured: ['translations' => $translations],
            model: $model,
            inputTokens: 100,
            outputTokens: 50,
            totalTokens: 150,
            latencyMs: 12,
        );

        $model = new FakeLanguageModel($completion);

        $this->app->instance(LanguageModel::class, $model);
        $this->app->instance(AiProviderFactory::class, new FakeAiProviderFactory($model));

        config()->set('ai.provider', 'fake');
        config()->set('ai.providers.fake', [
            'driver' => 'fake',
            'base_url' => '',
            'api_key' => null,
            'path' => '',
            'model' => 'fake',
            'transport' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return $this->withinTenant($this->tenant, fn (): Product => Product::factory()
            ->atStore($this->store)
            ->create($attributes + [
                'tenant_id' => $this->tenant->getKey(),
                'price' => 10.00,
                'cost' => 4.00,
            ]));
    }

    private function url(string $path = ''): string
    {
        return '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.$path;
    }

    public function test_generation_requires_authentication(): void
    {
        $product = $this->product();

        $this->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
            'fields' => ['name'],
            'target_locales' => ['ar'],
        ])->assertStatus(401);
    }

    public function test_a_generated_translation_stays_private_until_a_merchant_approves_it(): void
    {
        $product = $this->product([
            'name' => 'Fresh coffee',
            'description' => 'Roasted daily.',
        ]);

        $this->fakeTranslation([
            'name' => 'قهوة طازجة',
            'description' => 'محمصة يوميا.',
        ]);

        $response = $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name', 'description'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('data.results.ar.status', 'generated')
            ->assertJsonPath('data.translations.ar.name.status', 'generated')
            ->assertJsonPath('data.translations.ar.name.published_text', null);

        $translationId = (int) $response->json('data.translations.ar.name.id');

        $this->assertNull(
            Translation::query()->findOrFail($translationId)->published_text,
            'Generating a translation must never publish it.',
        );

        $this->getJson($this->url('/products/'.$product->slug).'?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.name', 'Fresh coffee');

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.published_text', 'قهوة طازجة');

        $this->getJson($this->url('/products/'.$product->slug).'?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.name', 'قهوة طازجة');
    }

    public function test_a_manual_edit_is_never_regenerated_without_confirmation(): void
    {
        $product = $this->product(['name' => 'Fresh coffee']);

        $this->fakeTranslation(['name' => 'قهوة طازجة']);

        $response = $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $translationId = (int) $response->json('data.translations.ar.name.id');

        $this->api($this->merchant)
            ->patchJson("/api/v1/tenant/translations/{$translationId}", [
                'translated_text' => 'قهوة يدوية',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.manually_edited', true);

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/regenerate", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'translation_confirmation_required');

        $this->assertSame(
            'قهوة يدوية',
            Translation::query()->findOrFail($translationId)->translated_text,
            'A refused regeneration must leave the manual copy untouched.',
        );

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/regenerate", [
                'confirm_overwrite' => true,
            ])
            ->assertOk();

        $this->assertSame(
            'قهوة طازجة',
            Translation::query()->findOrFail($translationId)->translated_text,
        );
    }

    public function test_changing_the_source_marks_translations_stale_and_keeps_the_approved_copy_public(): void
    {
        $product = $this->product(['name' => 'Fresh coffee']);

        $this->fakeTranslation(['name' => 'قهوة طازجة']);

        $response = $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $translationId = (int) $response->json('data.translations.ar.name.id');

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/approve")
            ->assertOk();

        $this->withinTenant($this->tenant, fn () => $product->forceFill(['name' => 'Fresh coffee beans'])->save());

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.translations.ar.name.status', 'stale')
            ->assertJsonPath('data.translations.ar.name.published_text', 'قهوة طازجة');

        // The previously approved copy stays visible until the stale row is
        // regenerated and approved; the source edit must not blank the page.
        $this->getJson($this->url('/products/'.$product->slug).'?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.name', 'قهوة طازجة');

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'translation_stale');
    }

    public function test_approve_all_only_approves_the_selected_rows_of_the_named_record(): void
    {
        $first = $this->product(['name' => 'Coffee beans', 'description' => 'Roasted daily.']);
        $second = $this->product(['name' => 'Green tea', 'description' => 'Light and fresh.']);

        $this->fakeTranslation([
            'name' => 'حبوب القهوة',
            'description' => 'محمصة يوميا.',
        ]);

        $firstRows = $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$first->getKey()}/generate", [
                'fields' => ['name', 'description'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $nameId = (int) $firstRows->json('data.translations.ar.name.id');
        $descriptionId = (int) $firstRows->json('data.translations.ar.description.id');

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$second->getKey()}/approve-all", [
                'ids' => [$nameId, $descriptionId],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'translation_selection_mismatch');

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$first->getKey()}/approve-all", [
                'ids' => [$nameId],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.approved');

        $this->assertSame(
            TranslationStatus::Approved,
            Translation::query()->findOrFail($nameId)->status,
        );
        $this->assertSame(
            TranslationStatus::Generated,
            Translation::query()->findOrFail($descriptionId)->status,
            'Approve-all must not touch a row the merchant did not select.',
        );
    }

    public function test_a_translation_from_another_workspace_is_not_reachable(): void
    {
        $other = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $other->getKey())
            ->firstOrFail();
        $otherStore->forceFill(['enabled_locales' => ['en', 'ar']])->save();
        $otherMerchant = $this->memberOf($other, 'merchant');

        $otherProduct = $this->withinTenant($other, fn (): Product => Product::factory()
            ->atStore($otherStore)
            ->create(['tenant_id' => $other->getKey(), 'name' => 'Secret blend']));

        $this->fakeTranslation(['name' => 'مزيج سري']);

        $generated = $this->api($otherMerchant, $other)
            ->postJson("/api/v1/tenant/translations/product/{$otherProduct->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $translationId = (int) $generated->json('data.translations.ar.name.id');

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$otherProduct->getKey()}")
            ->assertNotFound();

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/{$translationId}/approve")
            ->assertNotFound();
    }

    public function test_target_languages_come_from_the_store_enabled_list(): void
    {
        $this->store->forceFill(['enabled_locales' => ['en', 'fr']])->save();

        $product = $this->product(['name' => 'Fresh coffee']);

        $this->fakeTranslation(['name' => 'Café frais']);

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertStatus(409);

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['fr'],
                'source_locale' => 'en',
            ])
            ->assertOk()
            ->assertJsonPath('data.results.fr.status', 'generated');
    }

    public function test_source_language_is_detected_and_can_be_corrected(): void
    {
        $product = $this->product([
            'name' => 'قهوة طازجة محمصة يوميا',
            'description' => 'حبوب قهوة عربية طازجة من المزرعة مباشرة إلى بابك.',
        ]);

        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.source_locale', 'ar');

        $this->fakeTranslation([
            'name' => 'قهوة طازجة محمصة يوميا',
            'description' => 'حبوب قهوة عربية طازجة من المزرعة مباشرة إلى بابك.',
        ]);

        // An explicit correction wins over detection, even when it names the
        // language the dashboard is displayed in.
        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name', 'description'],
                'target_locales' => ['en'],
                'source_locale' => 'ar',
            ])
            ->assertOk()
            ->assertJsonPath('data.source_locale', 'ar');
    }

    public function test_ai_output_that_drops_a_placeholder_is_rejected_and_marked_failed(): void
    {
        $product = $this->product([
            'name' => 'Order update',
            'description' => 'Hello {{customer_name}}, your order costs $19.99.',
        ]);

        $this->fakeTranslation([
            'description' => 'مرحبا، طلبك يكلف ١٩.٩٩ دولار.',
        ]);

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['description'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'ai_invalid_response');

        $row = Translation::query()
            ->forRecord($product)
            ->forLocale('ar')
            ->where('field', 'description')
            ->firstOrFail();

        $this->assertSame(TranslationStatus::Failed, $row->status);
        $this->assertSame('translation_validation_failed', $row->metadata['reason'] ?? null);
    }

    public function test_usage_records_tokens_and_an_estimated_cost(): void
    {
        $product = $this->product(['name' => 'Fresh coffee']);

        $this->fakeTranslation(['name' => 'قهوة طازجة']);

        $this->api($this->merchant)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $response = $this->api($this->merchant)
            ->getJson('/api/v1/tenant/translations/usage')
            ->assertOk()
            ->assertJsonPath('data.requests', 1)
            ->assertJsonPath('data.total_tokens', 150);

        $this->assertGreaterThan(0, (float) $response->json('data.estimated_cost'));

        $usage = AiUsage::query()->where('feature', 'translation')->firstOrFail();

        $this->assertSame('fake', $usage->provider);
        $this->assertSame('grok-4.7', $usage->model);
        $this->assertSame(150, $usage->total_tokens);
        $this->assertNotNull($usage->estimated_cost);
    }

    public function test_staff_can_view_translations_but_not_generate_them(): void
    {
        $product = $this->product(['name' => 'Fresh coffee']);
        $staff = $this->memberOf($this->tenant, 'staff');

        $this->api($staff)
            ->getJson("/api/v1/tenant/translations/product/{$product->getKey()}")
            ->assertOk();

        $this->api($staff)
            ->postJson("/api/v1/tenant/translations/product/{$product->getKey()}/generate", [
                'fields' => ['name'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertForbidden();
    }

    public function test_platform_content_uses_the_platform_surface_only(): void
    {
        $faq = FaqEntry::factory()->create([
            'question' => 'Do you ship internationally?',
            'answer' => '<p>Yes, to selected countries.</p>',
        ]);

        $platformUser = $this->platformUser();

        $this->fakeTranslation(['question' => 'هل تشحنون دوليا؟']);

        $response = $this->actingAs($platformUser, 'sanctum')
            ->postJson("/api/v1/platform/translations/faq_entry/{$faq->getKey()}/generate", [
                'fields' => ['question'],
                'target_locales' => ['ar'],
                'source_locale' => 'en',
            ])
            ->assertOk();

        $translationId = (int) $response->json('data.translations.ar.question.id');

        $this->actingAs($platformUser, 'sanctum')
            ->postJson("/api/v1/platform/translations/{$translationId}/approve")
            ->assertOk();

        $this->getJson('/api/v1/public/faq?locale=ar')
            ->assertOk()
            ->assertJsonPath('data.0.question', 'هل تشحنون دوليا؟');

        // The same platform record is not reachable through a merchant token.
        $this->api($this->merchant)
            ->getJson("/api/v1/tenant/translations/faq_entry/{$faq->getKey()}")
            ->assertNotFound();
    }
}
