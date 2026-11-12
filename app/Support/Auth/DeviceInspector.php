<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Best-effort description of the client behind a request.
 *
 * The User-Agent string is advisory: it tells the security screen "Chrome on
 * macOS" for a person who recognises their own device, and nothing here is ever
 * used for authorization. Keeping the parsing in one place means the session
 * list, the new-device email and the security history all describe a device the
 * same way.
 */
final class DeviceInspector
{
    /**
     * @return array{browser: string|null, platform: string|null, device_type: string}
     */
    public static function describe(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return [
            'browser' => self::browser($agent),
            'platform' => self::platform($agent),
            'device_type' => self::deviceType($agent),
        ];
    }

    public static function labelFor(Request $request): string
    {
        $explicit = trim((string) $request->header('X-Device-Name', ''));

        if ($explicit !== '') {
            return mb_substr($explicit, 0, 255);
        }

        $described = self::describe($request->userAgent());
        $browser = $described['browser'];
        $platform = $described['platform'];

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    public static function browser(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    public static function platform(string $agent): ?string
    {
        return match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
    }

    public static function deviceType(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'iPad'), str_contains($agent, 'Tablet') => 'tablet',
            str_contains($agent, 'Mobile'), str_contains($agent, 'iPhone'), str_contains($agent, 'Android') => 'mobile',
            $agent === '' => 'unknown',
            default => 'desktop',
        };
    }
}
