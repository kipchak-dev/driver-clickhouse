<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Result;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * The rows a select() returned, with their column types and ClickHouse's statistics.
 *
 * Integers that fit in a PHP int are ints and larger ones (UInt64 above PHP_INT_MAX, Int128, Int256) are strings;
 * decimals are strings, so no precision is lost; DateTime values are strings in the column's timezone.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final readonly class Result implements IteratorAggregate, Countable
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $types Column name => ClickHouse type, in column order.
     * @param array<string, mixed>|null $totals The WITH TOTALS row.
     */
    public function __construct(
        public array $rows,
        public array $types,
        public string $queryId,
        public ?float $elapsed = null,
        public int $rowsRead = 0,
        public int $bytesRead = 0,
        public ?int $rowsBeforeLimit = null,
        public ?array $totals = null,
        public bool $cached = false,
    ) {
    }

    /**
     * Builds the result from ClickHouse's JSON output format.
     */
    public static function fromJson(array $json, string $queryId): self
    {
        $types = [];
        foreach ($json['meta'] ?? [] as $column) {
            $types[(string) $column['name']] = (string) $column['type'];
        }
        $statistics = $json['statistics'] ?? [];

        return new self(
            rows: array_values($json['data'] ?? []),
            types: $types,
            queryId: $queryId,
            elapsed: isset($statistics['elapsed']) ? (float) $statistics['elapsed'] : null,
            rowsRead: (int) ($statistics['rows_read'] ?? 0),
            bytesRead: (int) ($statistics['bytes_read'] ?? 0),
            rowsBeforeLimit: isset($json['rows_before_limit_at_least']) ? (int) $json['rows_before_limit_at_least'] : null,
            totals: isset($json['totals']) && is_array($json['totals']) ? $json['totals'] : null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * The first column of the first row, such as the count from SELECT count(), or null with no rows.
     */
    public function value(): mixed
    {
        $first = $this->first();

        return $first === null ? null : $first[array_key_first($first)];
    }

    /**
     * One column's values from every row.
     *
     * @return list<mixed>
     */
    public function column(string $name): array
    {
        if (!array_key_exists($name, $this->types)) {
            throw new InvalidArgumentException('The result has no column "' . $name . '"; it has ' . implode(', ', array_keys($this->types)) . '.');
        }

        return array_column($this->rows, $name);
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys($this->types);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'types' => $this->types,
            'queryId' => $this->queryId,
            'elapsed' => $this->elapsed,
            'rowsRead' => $this->rowsRead,
            'bytesRead' => $this->bytesRead,
            'rowsBeforeLimit' => $this->rowsBeforeLimit,
            'totals' => $this->totals,
        ];
    }

    /**
     * Rebuilds a result stored with toArray(), marked as cached.
     */
    public static function fromCache(array $stored): self
    {
        return new self(...[...$stored, 'cached' => true]);
    }
}
