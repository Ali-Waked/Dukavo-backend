<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Ai;

use App\Http\Requests\ApiRequest;
use Closure;

/**
 * The workspace's AI preferences: which features run and whether the
 * workspace opts into bringing its own provider credentials.
 *
 * The payload is a patch: features left out keep their current state, and
 * a feature the platform has disabled cannot be switched on from here.
 */
final class UpdateTenantAiSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'features' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $known = array_keys((array) config('ai.features'));

                    foreach (array_keys((array) $value) as $feature) {
                        if (! is_string($feature) || ! in_array($feature, $known, true)) {
                            $fail('The features list contains an unknown feature.');
                        }
                    }
                },
            ],
            'features.*' => ['boolean'],
            'byok_opt_in' => ['sometimes', 'boolean'],
        ];
    }
}
