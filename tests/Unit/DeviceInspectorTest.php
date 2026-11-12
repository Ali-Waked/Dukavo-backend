<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Auth\DeviceInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * User-Agent descriptions are display-only, but they must still be consistent:
 * the session list, the new-device email and the security history all use this
 * one parser.
 */
class DeviceInspectorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string}>
     */
    public static function agents(): array
    {
        return [
            'chrome windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Chrome', 'Windows', 'desktop',
            ],
            'safari iphone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
                'Safari', 'iOS', 'mobile',
            ],
            'firefox linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'Firefox', 'Linux', 'desktop',
            ],
            'edge android phone' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0',
                'Edge', 'Android', 'mobile',
            ],
            'ipad tablet' => [
                'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1',
                'Safari', 'iOS', 'tablet',
            ],
            'unknown' => ['', null, null, 'unknown'],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_describes_a_user_agent(string $agent, ?string $browser, ?string $platform, string $type): void
    {
        $described = DeviceInspector::describe($agent);

        $this->assertSame($browser, $described['browser']);
        $this->assertSame($platform, $described['platform']);
        $this->assertSame($type, $described['device_type']);
    }
}
