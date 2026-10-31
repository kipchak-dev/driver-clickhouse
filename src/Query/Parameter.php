<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Query;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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
 *  - DateTimeInterface → a Unix timestamp, so its timezone cannot be misread: whole seconds for a DateTime
 *    placeholder, which refuses a fraction, and with microseconds for DateTime64 (pass Date values as 'Y-m-d' strings)
 *  - a list → Array, an array with string keys → Map, both nesting
 *  - BackedEnum → its value, Stringable → its string
 */
final class Parameter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const string PLACEHOLDER = '/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([^}]+)\}/';

    /**
     * @param array<string, mixed> $parameters
     * @param string $sql The statement, for the types of its placeholders.
     * @return array<string, string> The query string entries, keyed param_<name>.
     */
    public static function toQuery(array $parameters, string $sql = ''): array
    {
        preg_match_all(self::PLACEHOLDER, $sql, $placeholders, PREG_SET_ORDER);
        $types = [];
        foreach ($placeholders as [, $name, $type]) {
            $types[$name] = $type;
        }

        $query = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || !preg_match(self::NAME, $name)) {
                throw new InvalidArgumentException(
                    'Query parameter names must be letters, digits and underscores, matching {name:Type} in the SQL; got ' . var_export($name, true) . '.'
                );
            }
            // Only DateTime64 reads a fractional timestamp; an unknown type keeps the microseconds.
            $type = $types[$name] ?? null;
            $query['param_' . $name] = self::format($value, $type === null || str_contains($type, 'DateTime64'));
        }

        return $query;
    }

    /**
     * The value as a top-level parameter: in ClickHouse's "escaped" text format.
     *
     * @param bool $microseconds Whether dates keep their microseconds (DateTime64) or are whole seconds (DateTime).
     */
    public static function format(mixed $value, bool $microseconds = true): string
    {
        $value = self::normalise($value, $microseconds);

        return match (true) {
            $value === null => '\\N',
            is_string($value) => strtr($value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\0" => '\\0']),
            is_array($value) => self::literal($value, $microseconds),
            default => self::scalar($value),
        };
    }

    /**
     * The value inside an Array or Map: in ClickHouse's "quoted" text format.
     */
    private static function literal(mixed $value, bool $microseconds): string
    {
        $value = self::normalise($value, $microseconds);

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
            return '[' . implode(',', array_map(fn(mixed $item) => self::literal($item, $microseconds), $value)) . ']';
        }

        $entries = [];
        foreach ($value as $key => $item) {
            $entries[] = self::literal(is_int($key) ? $key : (string) $key, $microseconds) . ':' . self::literal($item, $microseconds);
        }

        return '{' . implode(',', $entries) . '}';
    }

    private static function normalise(mixed $value, bool $microseconds): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $microseconds ? self::timestamp($value) : $value->format('U'),
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

    private static function insertTimestamp(DateTimeInterface $value): string
    {
        if ($value->format('u') === '000000') {
            return $value->format('U');
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    /**
     * The value for a JSONEachRow insert: dates as Unix timestamps (as strings, which DateTime and DateTime64
     * columns read), or with microseconds as ISO 8601 in UTC, because a DateTime column refuses a fractional
     * timestamp (the client sends date_time_input_format=best_effort with inserts to read them); enums as their
     * values.
     */
    public static function forInsert(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => self::insertTimestamp($value),
            $value instanceof BackedEnum => $value->value,
            is_array($value) => array_map(self::forInsert(...), $value),
            default => $value,
        };
    }
}
