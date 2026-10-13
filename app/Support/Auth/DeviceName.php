<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * A human label for the client a token was minted for.
 *
 * Sanctum's personal access tokens carry no user-agent column, so the only
 * place to record "which device is this?" is the token's own name, written when
 * the token is created. The parsing is deliberately shallow — it turns the
 * browser and platform most people recognise out of the User-Agent header and
 * gives up gracefully — because the value is a label on a settings screen, not
 * a security control.
 */
final class DeviceName
{
    /**
     * Resolve the label, preferring an explicit client-supplied name.
     */
    public static function for(Request $request): string
    {
        $explicit = trim((string) $request->header('X-Device-Name', ''));

        if ($explicit !== '') {
            return mb_substr($explicit, 0, 255);
        }

        $agent = (string) $request->userAgent();
        $browser = self::browser($agent);
        $platform = self::platform($agent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }

    private static function browser(string $agent): ?string
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

    private static function platform(string $agent): ?string
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
}
