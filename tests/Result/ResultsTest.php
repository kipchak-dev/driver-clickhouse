<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests\Result;

use InvalidArgumentException;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseException;
use Kipchak\Driver\ClickHouse\Result\Result;
use Kipchak\Driver\ClickHouse\Result\Summary;
use PHPUnit\Framework\TestCase;

final class ResultsTest extends TestCase
{
    private static function sample(): Result
    {
        return Result::fromJson([
            'meta' => [['name' => 'day', 'type' => 'Date'], ['name' => 'hits', 'type' => 'UInt64']],
            'data' => [['day' => '2026-10-28', 'hits' => 10], ['day' => '2026-10-29', 'hits' => 12]],
            'totals' => ['day' => '1970-01-01', 'hits' => 22],
            'rows' => 2,
            'rows_before_limit_at_least' => 40,
            'statistics' => ['elapsed' => 0.0012, 'rows_read' => 1000, 'bytes_read' => 8000],
        ], 'q-1');
    }

    public function testReadsTheJsonFormat(): void
    {
        $result = self::sample();

        $this->assertSame(['day' => 'Date', 'hits' => 'UInt64'], $result->types);
        $this->assertSame(['day', 'hits'], $result->columns());
        $this->assertCount(2, $result);
        $this->assertSame(['day' => '2026-10-28', 'hits' => 10], $result->first());
        $this->assertSame('2026-10-28', $result->value());
        $this->assertSame([10, 12], $result->column('hits'));
        $this->assertSame(40, $result->rowsBeforeLimit);
        $this->assertSame(22, $result->totals['hits']);
        $this->assertSame(1000, $result->rowsRead);
        $this->assertSame(0.0012, $result->elapsed);
        $this->assertSame('q-1', $result->queryId);
        $this->assertFalse($result->cached);
        $this->assertSame($result->rows, iterator_to_array($result));
    }

    public function testEmptyResult(): void
    {
        $result = Result::fromJson(['meta' => [['name' => 'x', 'type' => 'UInt8']], 'data' => []], 'q');

        $this->assertTrue($result->isEmpty());
        $this->assertNull($result->first());
        $this->assertNull($result->value());
        $this->assertSame([], $result->column('x'));
        $this->assertNull($result->rowsBeforeLimit);
    }

    public function testUnknownColumnIsExplained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no column "nope"; it has day, hits');
        self::sample()->column('nope');
    }

    public function testCacheRoundTrip(): void
    {
        $result = self::sample();
        $cached = Result::fromCache(unserialize(serialize($result->toArray())));

        $this->assertTrue($cached->cached);
        $this->assertSame($result->rows, $cached->rows);
        $this->assertSame($result->types, $cached->types);
        $this->assertSame($result->totals, $cached->totals);
        $this->assertSame($result->queryId, $cached->queryId);
    }

    public function testSummary(): void
    {
        $summary = Summary::fromHeader('q', '{"read_rows":"5","read_bytes":"40","written_rows":"5","written_bytes":"40","total_rows_to_read":"0","result_rows":"5","result_bytes":"0","elapsed_ns":"1000"}');

        $this->assertSame(5, $summary->writtenRows);
        $this->assertSame(1000, $summary->elapsedNs);

        $total = $summary->plus(Summary::fromHeader('q-2', '{"written_rows":"3","written_bytes":"24","elapsed_ns":"500"}'));
        $this->assertSame('q', $total->queryId);
        $this->assertSame(8, $total->writtenRows);
        $this->assertSame(64, $total->writtenBytes);
        $this->assertSame(1500, $total->elapsedNs);

        $this->assertSame(0, Summary::fromHeader('q', null)->writtenRows);
        $this->assertNull(Summary::fromHeader('q', 'not json')->elapsedNs);
    }

    public function testExceptionFromTextBody(): void
    {
        $exception = ClickHouseException::fromResponse(
            "Code: 60. DB::Exception: Unknown table expression identifier 'nope' in scope SELECT * FROM nope. (UNKNOWN_TABLE) (version 25.8.33.6 (official build))\n",
            404,
            'q-1',
            '60',
        );

        $this->assertSame(60, $exception->errorCode);
        $this->assertSame(60, $exception->getCode());
        $this->assertSame('UNKNOWN_TABLE', $exception->errorName);
        $this->assertSame(404, $exception->httpStatus);
        $this->assertSame('q-1', $exception->queryId);
        $this->assertSame("[ClickHouse] Code: 60. DB::Exception: Unknown table expression identifier 'nope' in scope SELECT * FROM nope. (UNKNOWN_TABLE)", $exception->getMessage());
    }

    public function testExceptionFromJsonBodyWithoutHeader(): void
    {
        $exception = ClickHouseException::fromResponse(
            '{"exception": "Code: 516. DB::Exception: kipchak: Authentication failed: password is incorrect. (AUTHENTICATION_FAILED) (version 25.8.33.6 (official build))"}',
            401,
            'q',
        );

        $this->assertSame(516, $exception->errorCode);
        $this->assertSame('AUTHENTICATION_FAILED', $exception->errorName);
        $this->assertStringNotContainsString('version', $exception->getMessage());
    }

    public function testExceptionWithoutBody(): void
    {
        $exception = ClickHouseException::fromResponse('', 502, null);

        $this->assertNull($exception->errorCode);
        $this->assertStringContainsString('HTTP 502', $exception->getMessage());
    }
}
