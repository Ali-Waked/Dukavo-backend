<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ContactStatus;
use App\Enums\NewsletterStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Enums\WaitlistStatus;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\PricingPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WaitlistEntry;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide aggregates behind the operator overview.
 *
 * Every figure is a real COUNT or GROUP BY against Dukavo's own tables, computed
 * in SQL; the only rows this service loads are small grouped result sets. Dukavo
 * has no billing engine, so no subscription, MRR or churn figure appears here:
 * a metric that cannot be derived from a real table is absent, never estimated.
 *
 * The selected window drives two different readings that must not be confused:
 * `*` (e.g. `tenants.total`) is a lifetime total, while `new` / `previous_new`
 * count rows *created* inside the current and preceding window respectively. The
 * frontend derives a comparison from those two raw counts rather than receiving
 * a pre-computed percentage, so an empty previous period stays "unavailable"
 * instead of becoming a misleading 0%.
 */
class PlatformDashboardService
{
    /**
     * Windows a caller may request. `days` sets the period length (and therefore
     * the previous period), `bucket` the time-series granularity.
     *
     * @var array<string, array{days: int, bucket: string}>
     */
    private const RANGES = [
        '7d' => ['days' => 7, 'bucket' => 'day'],
        '30d' => ['days' => 30, 'bucket' => 'day'],
        '90d' => ['days' => 90, 'bucket' => 'day'],
        '12m' => ['days' => 365, 'bucket' => 'month'],
    ];

    public const DEFAULT_RANGE = '30d';

    /**
     * @return list<string>
     */
    public static function ranges(): array
    {
        return array_keys(self::RANGES);
    }

    /**
     * The overview payload for one window.
     *
     * @return array<string, mixed>
     */
    public function overview(string $range): array
    {
        $config = self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE];

        [$from, $until] = $this->window($config);
        [$previousFrom, $previousUntil] = $this->previousWindow($config);

        // One grouped query, shared by the KPI `users` block and the status
        // meters, so the three buckets are computed exactly once per request.
        $accountBuckets = $this->userAccountBuckets();

        return [
            'range' => [
                'key' => $range,
                'bucket' => $config['bucket'],
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'previous_from' => $previousFrom->toDateString(),
                'previous_until' => $previousUntil->toDateString(),
            ],
            'tenants' => array_merge($this->tenantTotals(), [
                'new' => $this->createdBetween(Tenant::query(), $from, $until),
                'previous_new' => $this->createdBetween(Tenant::query(), $previousFrom, $previousUntil),
            ]),
            'users' => [
                'total' => User::query()->count(),
                // A platform user holds at least one role assignment with a NULL
                // tenant, counted by distinct user id so two platform roles are
                // one person.
                'platform' => $accountBuckets['platform_only'] + $accountBuckets['both'],
                // A workspace member holds at least one tenant-scoped role.
                'members' => $accountBuckets['members_only'] + $accountBuckets['both'],
                // Three-way classification: Dukavo staff, workspace members and
                // the people who are both (a Super Admin who also owns a store
                // sits in `both`, never twice in the two single buckets).
                'platform_only' => $accountBuckets['platform_only'],
                'members_only' => $accountBuckets['members_only'],
                'both' => $accountBuckets['both'],
                'new' => $this->createdBetween(User::query(), $from, $until),
                'previous_new' => $this->createdBetween(User::query(), $previousFrom, $previousUntil),
            ],
            'waitlist' => [
                'total' => WaitlistEntry::query()->count(),
                'pending' => WaitlistEntry::query()->where('status', WaitlistStatus::Pending)->count(),
                'joined' => WaitlistEntry::query()->where('status', WaitlistStatus::Joined)->count(),
                'new' => $this->createdBetween(WaitlistEntry::query(), $from, $until),
                'previous_new' => $this->createdBetween(WaitlistEntry::query(), $previousFrom, $previousUntil),
            ],
            'contact_messages' => [
                'total' => ContactMessage::query()->count(),
                'unresolved' => ContactMessage::query()->where('status', ContactStatus::New)->count(),
                'new' => $this->createdBetween(ContactMessage::query(), $from, $until),
                'previous_new' => $this->createdBetween(ContactMessage::query(), $previousFrom, $previousUntil),
            ],
            'newsletter_subscribers' => [
                'total' => NewsletterSubscriber::query()->count(),
                'subscribed' => NewsletterSubscriber::query()
                    ->where('status', NewsletterStatus::Subscribed)
                    ->count(),
                'new' => $this->createdBetween(NewsletterSubscriber::query(), $from, $until),
                'previous_new' => $this->createdBetween(NewsletterSubscriber::query(), $previousFrom, $previousUntil),
            ],
            // Plan *configuration*, not subscribers: the pricing table is
            // marketing copy Dukavo edits, and nothing here implies a customer.
            'plans' => [
                'total' => PricingPlan::query()->count(),
                'active' => PricingPlan::query()->where('active', true)->count(),
                'highlighted' => PricingPlan::query()->where('highlighted', true)->count(),
            ],
            'series' => $this->series($config, $from, $until),
            'status' => $this->status($accountBuckets),
        ];
    }

    /**
     * Lifetime tenant totals, independent of the selected window.
     *
     * @return array{total: int, active: int, suspended: int}
     */
    private function tenantTotals(): array
    {
        return [
            'total' => Tenant::query()->count(),
            'active' => Tenant::query()->where('status', TenantStatus::Active)->count(),
            'suspended' => Tenant::query()->where('status', TenantStatus::Suspended)->count(),
        ];
    }

    /**
     * The current window as [start of first day, now]. A month range starts at
     * the first of the month eleven months back so it covers twelve buckets.
     *
     * @param  array{days: int, bucket: string}  $config
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $config): array
    {
        $until = CarbonImmutable::now();

        $from = $config['bucket'] === 'month'
            ? $until->startOfMonth()->subMonths(11)
            : $until->startOfDay()->subDays($config['days'] - 1);

        return [$from, $until];
    }

    /**
     * The equal-length window immediately before the current one.
     *
     * @param  array{days: int, bucket: string}  $config
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousWindow(array $config): array
    {
        [$from] = $this->window($config);

        if ($config['bucket'] === 'month') {
            return [$from->startOfMonth()->subMonths(12), $from->subSecond()];
        }

        $previousUntil = $from->subDay()->endOfDay();
        $previousFrom = $previousUntil->subDays($config['days'] - 1)->startOfDay();

        return [$previousFrom, $previousUntil];
    }

    /**
     * @param  Builder<*>  $query
     */
    private function createdBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return $query->whereBetween('created_at', [$from, $until])->count();
    }

    /**
     * The three-way account classification, as one grouped aggregate.
     *
     * `user_roles` is folded down to one row per user ("has a platform role?",
     * "has a tenant role?") and the three buckets are summed in SQL, so the
     * caller never handles a row per user.
     *
     * @return array{platform_only: int, members_only: int, both: int}
     */
    private function userAccountBuckets(): array
    {
        $perUser = UserRole::query()
            ->select('user_id')
            ->selectRaw('MAX(CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END) AS has_platform')
            ->selectRaw('MAX(CASE WHEN tenant_id IS NOT NULL THEN 1 ELSE 0 END) AS has_member')
            ->groupBy('user_id');

        $row = DB::table($perUser)
            ->selectRaw('SUM(CASE WHEN has_platform = 1 AND has_member = 0 THEN 1 ELSE 0 END) AS platform_only')
            ->selectRaw('SUM(CASE WHEN has_platform = 0 AND has_member = 1 THEN 1 ELSE 0 END) AS members_only')
            ->selectRaw('SUM(CASE WHEN has_platform = 1 AND has_member = 1 THEN 1 ELSE 0 END) AS both')
            ->first();

        return [
            'platform_only' => (int) ($row->platform_only ?? 0),
            'members_only' => (int) ($row->members_only ?? 0),
            'both' => (int) ($row->both ?? 0),
        ];
    }

    /**
     * New records per bucket across the window, oldest first, with empty buckets
     * present as zeroes so a chart's time axis stays honest about quiet periods.
     *
     * @param  array{days: int, bucket: string}  $config
     * @return list<array<string, int|string>>
     */
    private function series(array $config, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $bucket = $config['bucket'];
        $expression = $this->bucketExpression('created_at', $bucket);

        $counts = [
            'tenants' => $this->bucketCounts(Tenant::query(), $expression, $from, $until),
            'users' => $this->bucketCounts(User::query(), $expression, $from, $until),
            'waitlist' => $this->bucketCounts(WaitlistEntry::query(), $expression, $from, $until),
            'contact_messages' => $this->bucketCounts(ContactMessage::query(), $expression, $from, $until),
            'newsletter_subscribers' => $this->bucketCounts(NewsletterSubscriber::query(), $expression, $from, $until),
        ];

        $cursor = $config['bucket'] === 'month' ? $from->startOfMonth() : $from->startOfDay();
        $series = [];

        while ($cursor->lte($until)) {
            $key = $config['bucket'] === 'month' ? $cursor->format('Y-m') : $cursor->toDateString();

            $series[] = [
                'bucket' => $key,
                'tenants' => (int) ($counts['tenants'][$key] ?? 0),
                'users' => (int) ($counts['users'][$key] ?? 0),
                'waitlist' => (int) ($counts['waitlist'][$key] ?? 0),
                'contact_messages' => (int) ($counts['contact_messages'][$key] ?? 0),
                'newsletter_subscribers' => (int) ($counts['newsletter_subscribers'][$key] ?? 0),
            ];

            $cursor = $config['bucket'] === 'month' ? $cursor->addMonth() : $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  Builder<*>  $query
     * @return array<string, int>
     */
    private function bucketCounts(
        Builder $query,
        string $expression,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        return $query
            ->selectRaw("{$expression} as bucket_key, COUNT(*) as aggregate")
            ->whereBetween('created_at', [$from, $until])
            ->groupBy('bucket_key')
            ->pluck('aggregate', 'bucket_key')
            ->map(fn (int|string $count): int => (int) $count)
            ->all();
    }

    /**
     * The SQL that reduces a timestamp column to a groupable bucket key. Kept
     * per driver because there is no portable month truncation in SQLite.
     */
    private function bucketExpression(string $column, string $bucket): string
    {
        if ($bucket !== 'month') {
            return "DATE({$column})";
        }

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlsrv' => "CONVERT(char(7), {$column}, 120)",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    /**
     * Counts per status for every status-bearing platform table, so the number
     * of bars and their order come from the enum rather than a hardcoded list.
     * The account buckets are passed in because `overview()` already computed
     * them for the KPI block.
     *
     * @param  array{platform_only: int, members_only: int, both: int}  $accountBuckets
     * @return array<string, list<array{value: string, count: int}>>
     */
    private function status(array $accountBuckets): array
    {
        return [
            'tenants' => $this->statusCounts(Tenant::query(), TenantStatus::cases()),
            'users' => $this->statusCounts(User::query(), UserStatus::cases()),
            'waitlist' => $this->statusCounts(WaitlistEntry::query(), WaitlistStatus::cases()),
            'contact_messages' => $this->statusCounts(ContactMessage::query(), ContactStatus::cases()),
            'newsletter_subscribers' => $this->statusCounts(
                NewsletterSubscriber::query(),
                NewsletterStatus::cases(),
            ),
            'user_accounts' => [
                ['value' => 'platform_only', 'count' => $accountBuckets['platform_only']],
                ['value' => 'members_only', 'count' => $accountBuckets['members_only']],
                ['value' => 'both', 'count' => $accountBuckets['both']],
            ],
        ];
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<int, BackedEnum>  $cases
     * @return list<array{value: string, count: int}>
     */
    private function statusCounts(Builder $query, array $cases): array
    {
        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return array_values(array_map(
            fn (BackedEnum $case): array => [
                'value' => (string) $case->value,
                'count' => (int) ($counts[$case->value] ?? 0),
            ],
            $cases,
        ));
    }
}
