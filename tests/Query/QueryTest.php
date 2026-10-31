<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Kipchak\Driver\ClickHouse\Query\Identifier;
use Kipchak\Driver\ClickHouse\Query\Parameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum Plan: string
{
    case Pro = 'pro';
}

final class QueryTest extends TestCase
{
    public static function values(): iterable
    {
        yield 'null' => [null, '\\N'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [-42, '-42'];
        yield 'float' => [0.1, '0.1'];
        yield 'whole float' => [2.0, '2.0'];
        yield 'infinity' => [INF, 'inf'];
        yield 'nan' => [NAN, 'nan'];
        yield 'plain string' => ['hello', 'hello'];
        yield 'escaped string' => ["a\\b\tc\nd\re\0f'g", "a\\\\b\\tc\\nd\\re\\0f'g"];
        yield 'date' => [new DateTimeImmutable('2026-10-29 07:30:00 UTC'), '1793259000'];
        yield 'date with microseconds' => [new DateTimeImmutable('2026-10-29 07:30:00.000250 UTC'), '1793259000.000250'];
        yield 'date in another timezone is the same instant' => [new DateTimeImmutable('2026-10-29 08:30:00 +01:00'), '1793259000'];
        yield 'enum' => [Plan::Pro, 'pro'];
        yield 'list' => [[1, 2, 3], '[1,2,3]'];
        yield 'list of strings' => [["it's", 'a\\b', null], "['it\\'s','a\\\\b',NULL]"];
        yield 'nested list' => [[[1], []], '[[1],[]]'];
        yield 'map' => [['a' => 1, 'b' => [true]], "{'a':1,'b':[true]}"];
        yield 'list of dates' => [[new DateTimeImmutable('@1700000000')], "['1700000000']"];
    }

    #[DataProvider('values')]
    public function testFormatsValues(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Parameter::format($value));
    }

    public function testToQueryPrefixesNames(): void
    {
        $this->assertSame(['param_user' => '42', 'param_from' => '2026-01-01'], Parameter::toQuery(['user' => 42, 'from' => '2026-01-01']));
    }

    public function testDatesFollowTheirPlaceholdersType(): void
    {
        $at = new DateTimeImmutable('2026-10-29 07:30:00.000250 UTC');

        $this->assertSame(
            ['param_from' => '1793259000', 'param_to' => '1793259000.000250', 'param_days' => "['1793259000']", 'param_other' => '1793259000.000250'],
            Parameter::toQuery(
                ['from' => $at, 'to' => $at, 'days' => [$at], 'other' => $at],
                'SELECT 1 WHERE at >= { from : DateTime } AND at < {to:DateTime64(6)} AND day IN {days:Array(Nullable(DateTime))}',
            )
        );
    }

    public function testRejectsBadNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{name:Type}');
        Parameter::toQuery(['user id' => 1]);
    }

    public function testRejectsUnsupportedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass cannot be sent');
        Parameter::format(new \stdClass());
    }

    public function testInsertValues(): void
    {
        $this->assertSame(
            ['at' => '2023-11-14T22:13:20.500000Z', 'plan' => 'pro', 'tags' => ['a'], 'n' => 1],
            Parameter::forInsert(['at' => new DateTimeImmutable('@1700000000.5'), 'plan' => Plan::Pro, 'tags' => ['a'], 'n' => 1])
        );
    }

    public function testQuotesIdentifiers(): void
    {
        $this->assertSame('`events`', Identifier::quote('events'));
        $this->assertSame('`analytics`.`events`', Identifier::quote('analytics.events'));
        $this->assertSame('`a\\`b`', Identifier::quote('a`b'));
        $this->assertSame('`a.b`', Identifier::quoteOne('a.b'));
    }

    public function testRejectsBadIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifier::quote('a.b.c');
    }

    public function testRejectsEmptyIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifier::quote('analytics.');
    }
}
