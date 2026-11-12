<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * A human label for the client a token was minted for.
 *
 * The parsing lives in {@see DeviceInspector} so the session list, security
 * history and new-device email all agree. This class keeps the original narrow
 * contract used by the login response.
 */
final class DeviceName
{
    public static function for(Request $request): string
    {
        return DeviceInspector::labelFor($request);
    }
}
