<?php

declare(strict_types=1);

namespace App\Services\Automation;

/**
 * Resolves `{{ field }}` placeholders inside trusted action configuration.
 *
 * Only values from the event context can be substituted. Unknown placeholders
 * become empty strings, and no expression, method call or object is ever
 * evaluated.
 */
final class AutomationTemplateResolver
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function resolve(mixed $value, array $context, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return null;
        }

        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item, $context, $depth + 1);
            }

            return $resolved;
        }

        if (! is_string($value)) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $match) use ($context): string {
                $replacement = $context[$match[1]] ?? '';

                return is_scalar($replacement) ? (string) $replacement : '';
            },
            $value,
        );
    }
}
