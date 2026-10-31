<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Query;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Formats PHP values for ClickHouse's query parameters: `{name:Type}` placeholders in the SQL, whose values travel
 * separately from the query as `param_<name>` and are parsed by the server as the placeholder's type. Values are
 * never pasted into the SQL, so they cannot change the query.
 *
 *  - null → NULL (for Nullable types)
 *  - bool → true / false (for Bool)
 *  - int, float, string → as they are; strings are escaped
 *  - DateTimeInterface → a Unix timestamp, with microseconds when it has them, so its timezone cannot be misread
 *    (for DateTime and DateTime64; pass Date values as 'Y-m-d' strings)
 *  - a list → Array, an array with string keys → Map, both nesting
 *  - BackedEnum → its value, Stringable → its string
 */
final class Parameter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, string> The query string entries, keyed param_<name>.
     */
    public static function toQuery(array $parameters): array
    {
        $query = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException(
                    'Query parameter names must be letters, digits and underscores, matching {name:Type} in the SQL; got ' . var_export($name, true) . '.'
                );
            }
            $query['param_' . $name] = self::format($value);
        }

        return $query;
    }

    /**
     * The value as a top-level parameter: in ClickHouse's "escaped" text format.
     */
    public static function format(mixed $value): string
    {
        $value = self::normalise($value);

        return match (true) {
            $value === null => '\\N',
            is_string($value) => strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\0" => '\\0']),
            is_array($value) => self::literal($value),
            default => self::scalar($value),
        };
    }

    /**
     * The value inside an Array or Map: in ClickHouse's "quoted" text format.
     */
    private static function literal(mixed $value): string
    {
        $value = self::normalise($value);

        if ($value === null) {
            return 'NULL';
        }
        if (is_string($value)) {
            return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\0" => '\\0']) . "'";
        }
        if (!is_array($value)) {
            return self::scalar($value);
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(self::literal(...), $value)) . ']';
        }

        $entries = [];
        foreach ($value as $key => $item) {
            $entries[] = self::literal(is_int($key) ? $key : (string) $key) . ':' . self::literal($item);
        }

        return '{' . implode(',', $entries) . '}';
    }

    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => self::timestamp($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            default => throw new InvalidArgumentException(
                'A ' . get_debug_type($value) . ' cannot be sent as a ClickHouse query parameter.'
            ),
        };
    }

    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        // The shortest form that reads back as the same float.
        return var_export($value, true);
    }

    private static function timestamp(DateTimeInterface $value): string
    {
        $microseconds = $value->format('u');

        return $microseconds === '000000' ? $value->format('U') : $value->format('U') . '.' . $microseconds;
    }

    /**
     * The value for a JSONEachRow insert: dates as Unix timestamps (as strings, which DateTime and DateTime64
     * columns read), enums as their values.
     */
    public static function forInsert(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => self::timestamp($value),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => array_map(self::forInsert(...), $value),
            default => $value,
        };
    }
}
