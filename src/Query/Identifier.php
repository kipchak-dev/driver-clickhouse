<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Query;

use InvalidArgumentException;

/**
 * Quotes table, database and column names for SQL the driver writes itself (such as insert()). In your own
 * queries, prefer the {name:Identifier} query parameter.
 */
final class Identifier
{
    /**
     * Quotes a name, or a "database.table" pair: `events` or `analytics`.`events`.
     */
    public static function quote(string $name): string
    {
        $parts = explode('.', $name);
        if (count($parts) > 2) {
            throw new InvalidArgumentException('"' . $name . '" is not a table name or a database.table pair; quote names containing dots with Identifier::quoteOne().');
        }

        return implode('.', array_map(self::quoteOne(...), $parts));
    }

    /**
     * Quotes one name as it is, dots included.
     */
    public static function quoteOne(string $name): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('An identifier cannot be empty.');
        }

        return '`' . strtr($name, ['\\' => '\\\\', '`' => '\\`']) . '`';
    }
}
