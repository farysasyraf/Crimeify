<?php

namespace Farysasyraf\SavedRoutes;

/**
 * A saved route's parameters: the names of the values at the end of its address, stored joined by /, like id/kw.
 * A name ending in ? is optional.
 */
final class Parameters
{
    /**
     * Tidy what was typed into names joined by /: "id, kw" or "{id}/{kw}" both become id/kw.
     * Returns null when a name isn't valid, is used twice, or a required one follows an optional one.
     */
    public static function parse(string $typed): ?string
    {
        $names = [];
        $seen = [];
        $optionalSeen = false;

        foreach (preg_split('~[\s,/]+~', trim($typed), flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $part = preg_replace('/^\{(.*)\}$/', '$1', $part);

            // Up to 32 characters, which is as long as Laravel's router allows.
            if (! preg_match('/^([A-Za-z_]\w{0,31})(\?)?$/', $part, $match)) {
                return null;
            }

            $optional = isset($match[2]);

            if (in_array(strtolower($match[1]), $seen) || ($optionalSeen && ! $optional)) {
                return null;
            }

            $seen[] = strtolower($match[1]);
            $optionalSeen = $optional;
            $names[] = $match[1].($optional ? '?' : '');
        }

        return $names === [] ? null : implode('/', $names);
    }

    /**
     * The address as Laravel registers it: the path, then a placeholder for each parameter, like reports/{id}/{kw}.
     */
    public static function uri(string $path, ?string $parameters): string
    {
        $placeholders = array_map(fn (string $name) => '{'.$name.'}', self::names($parameters));

        return implode('/', [$path, ...$placeholders]);
    }

    /**
     * Whether every parameter is optional, so the bare path opens the route. True when there are none.
     */
    public static function allOptional(?string $parameters): bool
    {
        foreach (self::names($parameters) as $name) {
            if (! str_ends_with($name, '?')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function names(?string $parameters): array
    {
        return array_values(array_filter(explode('/', (string) $parameters), fn (string $name) => $name !== ''));
    }
}
