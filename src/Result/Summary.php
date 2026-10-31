<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Result;

/**
 * What a statement did, from ClickHouse's X-ClickHouse-Summary header: rows and bytes read and written.
 */
final readonly class Summary
{
    public function __construct(
        public string $queryId,
        public int $readRows = 0,
        public int $readBytes = 0,
        public int $writtenRows = 0,
        public int $writtenBytes = 0,
        public int $totalRowsToRead = 0,
        public int $resultRows = 0,
        public ?int $elapsedNs = null,
    ) {
    }

    /**
     * @param string|null $header The X-ClickHouse-Summary header: {"read_rows":"0","written_rows":"2",...}
     */
    public static function fromHeader(string $queryId, ?string $header): self
    {
        $summary = $header === null || $header === '' ? [] : json_decode($header, true);
        $summary = is_array($summary) ? $summary : [];
        $int = static fn (string $key): int => (int) ($summary[$key] ?? 0);

        return new self(
            queryId: $queryId,
            readRows: $int('read_rows'),
            readBytes: $int('read_bytes'),
            writtenRows: $int('written_rows'),
            writtenBytes: $int('written_bytes'),
            totalRowsToRead: $int('total_rows_to_read'),
            resultRows: $int('result_rows'),
            elapsedNs: isset($summary['elapsed_ns']) ? (int) $summary['elapsed_ns'] : null,
        );
    }

    /**
     * Adds another batch's summary to this one, for inserts sent in several batches.
     */
    public function plus(self $other): self
    {
        return new self(
            queryId: $this->queryId,
            readRows: $this->readRows + $other->readRows,
            readBytes: $this->readBytes + $other->readBytes,
            writtenRows: $this->writtenRows + $other->writtenRows,
            writtenBytes: $this->writtenBytes + $other->writtenBytes,
            totalRowsToRead: $this->totalRowsToRead + $other->totalRowsToRead,
            resultRows: $this->resultRows + $other->resultRows,
            elapsedNs: $this->elapsedNs === null && $other->elapsedNs === null ? null : (int) $this->elapsedNs + (int) $other->elapsedNs,
        );
    }
}
