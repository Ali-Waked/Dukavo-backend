<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\HelpArticleStatus;
use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpTag;
use App\Models\User;
use Database\Seeders\Support\DemoHelpCenter;
use Illuminate\Database\Seeder;

/**
 * The Help Center, seeded from the merchant guide.
 *
 * Dukavo has one documentation surface for merchants in the product — this
 * Help Center — and it condenses the same repository documentation the
 * platform team maintains, so the seeded copy is verified rather than
 * invented. Every article documents implemented behaviour.
 *
 * Idempotent by slug: a second run updates the demonstration articles in
 * place and never touches anything an editor has added under a different
 * slug. Counters (views, helpful votes) are seeded at zero and no feedback
 * or search-log rows are created — the analytics screens show real activity
 * only, which in a fresh demo database is honestly none.
 */
class HelpCenterSeeder extends Seeder
{
    /**
     * Editor-curated related reading, by slug. The bodies already link
     * between guides; these drive the "related articles" panel.
     *
     * @var array<string, list<string>>
     */
    private const RELATED = [
        'create-your-workspace' => ['build-your-first-sale', 'log-in-and-secure-your-account'],
        'build-your-first-sale' => [
            'create-a-product',
            'track-and-adjust-stock',
            'set-up-delivery-zones',
            'publish-your-storefront',
            'connect-whatsapp',
        ],
        'create-a-product' => ['organise-your-catalogue-with-categories', 'translate-your-content'],
        'manage-orders' => ['accept-payments', 'track-and-adjust-stock'],
        'invite-your-team-and-assign-roles' => ['log-in-and-secure-your-account'],
    ];

    /**
     * Fixed publication dates, oldest first, so a re-seed never reshuffles
     * the "recently updated" surfaces.
     *
     * @var array<string, string>
     */
    private const PUBLISHED_AT = [
        'create-your-workspace' => '2026-11-01 09:00:00',
        'log-in-and-secure-your-account' => '2026-11-01 09:10:00',
        'create-a-product' => '2026-11-02 10:00:00',
        'organise-your-catalogue-with-categories' => '2026-11-02 10:20:00',
        'track-and-adjust-stock' => '2026-11-03 09:00:00',
        'manage-orders' => '2026-11-03 09:30:00',
        'publish-your-storefront' => '2026-11-04 08:30:00',
        'set-up-delivery-zones' => '2026-11-04 08:40:00',
        'accept-payments' => '2026-11-05 09:00:00',
        'automate-your-work' => '2026-11-06 11:00:00',
        'connect-whatsapp' => '2026-11-06 11:30:00',
        'translate-your-content' => '2026-11-07 09:00:00',
        'invite-your-team-and-assign-roles' => '2026-11-08 09:00:00',
        'build-your-first-sale' => '2026-11-09 10:00:00',
        'understanding-the-dashboard' => '2026-11-10 09:00:00',
    ];

    public function run(): void
    {
        $author = User::query()->where('email', 'admin@gmail.com')->first();

        $categories = $this->seedCategories();
        $tags = $this->seedTags();

        $articles = [];
        $positionInCategory = [];

        foreach (DemoHelpCenter::articles() as $definition) {
            $categoryId = $categories[$definition['category']] ?? null;

            if ($categoryId === null) {
                continue;
            }

            $status = $definition['published']
                ? HelpArticleStatus::Published
                : HelpArticleStatus::InReview;

            $positionInCategory[$definition['category']] = ($positionInCategory[$definition['category']] ?? 0) + 1;
            $publishedAt = $status === HelpArticleStatus::Published
                ? (self::PUBLISHED_AT[$definition['slug']] ?? '2026-11-10 12:00:00')
                : null;

            $article = HelpArticle::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'category_id' => $categoryId,
                    'author_id' => $author?->getKey(),
                    'title' => $definition['title'],
                    'title_ar' => $definition['title_ar'],
                    'excerpt' => $definition['excerpt'],
                    'excerpt_ar' => $definition['excerpt_ar'],
                    'body' => $definition['body'],
                    'body_ar' => $definition['body_ar'],
                    'cover_image_path' => null,
                    'cover_image_disk' => null,
                    'video_url' => null,
                    'status' => $status,
                    'visibility' => HelpArticleVisibility::from($definition['visibility']),
                    'is_featured' => $definition['featured'],
                    'sort_order' => $positionInCategory[$definition['category']],
                    'published_at' => $publishedAt,
                    // Counters stay at zero: the analytics screens report real
                    // reader activity, which a fresh seed honestly has none of.
                    'view_count' => 0,
                    'helpful_count' => 0,
                    'unhelpful_count' => 0,
                ],
            );

            $article->tags()->sync(array_values(array_filter(
                array_map(static fn (string $slug): ?int => $tags[$slug] ?? null, $definition['tags']),
                static fn (?int $id): bool => $id !== null,
            )));

            $articles[$definition['slug']] = $article;
        }

        foreach (self::RELATED as $slug => $relatedSlugs) {
            $article = $articles[$slug] ?? null;

            if ($article === null) {
                continue;
            }

            $article->relatedArticles()->sync(array_values(array_filter(
                array_map(static fn (string $relatedSlug): ?int => $articles[$relatedSlug]?->getKey(), $relatedSlugs),
                static fn (?int $id): bool => $id !== null,
            )));
        }

        $this->command?->info(sprintf(
            'Help Center: %d categor(y|ies), %d tag(s), %d article(s) (%d published, %d in review).',
            count($categories),
            count($tags),
            count($articles),
            count(array_filter($articles, static fn (HelpArticle $article): bool => $article->status === HelpArticleStatus::Published)),
            count(array_filter($articles, static fn (HelpArticle $article): bool => $article->status === HelpArticleStatus::InReview)),
        ));
    }

    /**
     * @return array<string, int> slug => id
     */
    private function seedCategories(): array
    {
        $ids = [];

        foreach (DemoHelpCenter::categories() as $position => $definition) {
            $category = HelpCategory::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'name_ar' => $definition['name_ar'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                    'sort_order' => $position,
                    'is_active' => true,
                    'is_featured' => $definition['featured'],
                ],
            );

            $ids[$definition['slug']] = (int) $category->getKey();
        }

        return $ids;
    }

    /**
     * Tags are derived from the articles' tag lists, so the catalogue and the
     * content cannot drift apart.
     *
     * @return array<string, int> slug => id
     */
    private function seedTags(): array
    {
        $slugs = collect(DemoHelpCenter::articles())
            ->flatMap(static fn (array $definition): array => $definition['tags'])
            ->unique()
            ->values()
            ->all();

        $ids = [];

        foreach ($slugs as $slug) {
            $tag = HelpTag::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('-', ' ', $slug))],
            );

            $ids[$slug] = (int) $tag->getKey();
        }

        return $ids;
    }
}
