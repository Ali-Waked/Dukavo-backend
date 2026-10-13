<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\FaqEntry;
use App\Models\PricingPlan;
use Illuminate\Database\Seeder;

/**
 * Public marketing content: pricing plans and FAQ entries.
 *
 * Idempotent by construction — `updateOrCreate` keyed on each row's natural
 * identifier (slug, question) — so a fresh seed and a re-seed converge on the
 * same content. No RNG and no `fake()` calls: two runs produce byte-identical
 * rows, like every other seeder in this directory.
 *
 * Honesty rules baked into this data:
 *   - a plan whose price has not been decided keeps a NULL price (the API
 *     surfaces that as coming soon rather than as zero);
 *   - every FAQ answer describes capability the product actually implements —
 *     no roadmap promises, no invented integrations.
 */
class MarketingContentSeeder extends Seeder
{
    /**
     * Bullet lists only reference features that exist in the product today:
     * products, inventory, orders, customers, conversations, WhatsApp,
     * roles and permissions.
     *
     * @var list<array{slug: string, name: string, description: string, price: float|null, highlighted: bool, features: list<string>}>
     */
    private const array PLANS = [
        [
            'slug' => 'free',
            'name' => 'Free',
            'description' => 'Free during Dukavo early access: everything a merchant needs to run a store — catalogue, stock, orders, customers and the shared inbox.',
            'price' => 0.00,
            'highlighted' => false,
            'features' => [
                'Products, variants, categories and images',
                'Per store inventory with a full movement ledger',
                'Order lifecycle from pending to delivered',
                'Customer records with addresses and order history',
                'Shared inbox with WhatsApp connection',
                'Roles and permissions for your team',
            ],
        ],
        [
            'slug' => 'growth',
            'name' => 'Growth',
            'description' => 'For growing teams that need tighter control over who can do what. Final pricing will be published before launch.',
            'price' => null,
            'highlighted' => true,
            'features' => [
                'Everything in Free',
                'Granular roles across every module',
                'Multiple stores per workspace',
                'Dashboard reporting on revenue and stock',
            ],
        ],
        [
            'slug' => 'pro',
            'name' => 'Pro',
            'description' => 'For larger operations running several brands. Final pricing will be published before launch.',
            'price' => null,
            'highlighted' => false,
            'features' => [
                'Everything in Growth',
                'Multiple workspaces for separate brands',
                'Audit trail across administrative actions',
                'Priority onboarding support',
            ],
        ],
    ];

    /**
     * Each answer is limited to what the code in this repository can do today.
     *
     * @var list<array{question: string, answer: string, category: string}>
     */
    private const array FAQS = [
        [
            'question' => 'Can I run more than one store?',
            'answer' => 'Yes. A workspace can hold several stores, each with its own inventory, currency and timezone, and you choose which store an order or product belongs to. Stock is tracked per store, so every location always knows its own levels.',
            'category' => 'general',
        ],
        [
            'question' => 'How does inventory tracking work?',
            'answer' => 'Every product variant holds stock at a store. Adjustments, receives and sales all write a movement, so the ledger always explains how a quantity got where it is. Low stock alerts surface what needs reordering before it runs out.',
            'category' => 'general',
        ],
        [
            'question' => 'Does Dukavo connect to WhatsApp?',
            'answer' => 'Yes. You can connect a WhatsApp Business account and reply to customers from the shared inbox. Messages arriving on that number become conversations tied to the customer, so context never gets lost between channels.',
            'category' => 'channels',
        ],
        [
            'question' => 'Can I control what my staff can access?',
            'answer' => 'Yes. Roles are built from permissions scoped per module — products, orders, inventory, conversations and the rest — so a stock clerk never sees billing settings and a support agent can reply to conversations without touching the catalogue.',
            'category' => 'teams',
        ],
        [
            'question' => 'Is my data separated from other businesses?',
            'answer' => 'Completely. Every workspace is isolated at the database layer: products, orders, customers and conversations of one business are never visible to another, and every query is scoped to the workspace you are signed in to.',
            'category' => 'security',
        ],
        [
            'question' => 'What does the Free plan include?',
            'answer' => 'The Free plan covers the whole operating core while Dukavo is in early access: catalogue, inventory, orders, customers, the shared inbox with WhatsApp, and roles for your team. Growth and Pro pricing will be published before launch.',
            'category' => 'pricing',
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $index => $plan) {
            PricingPlan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'price' => $plan['price'],
                    'billing_interval' => BillingInterval::Monthly,
                    'currency' => 'USD',
                    'features' => $plan['features'],
                    'limits' => null,
                    'highlighted' => $plan['highlighted'],
                    'active' => true,
                    'sort_order' => $index * 10,
                ],
            );
        }

        foreach (self::FAQS as $index => $faq) {
            FaqEntry::query()->updateOrCreate(
                ['question' => $faq['question']],
                [
                    'answer' => $faq['answer'],
                    'category' => $faq['category'],
                    'sort_order' => $index * 10,
                    'active' => true,
                ],
            );
        }
    }
}
