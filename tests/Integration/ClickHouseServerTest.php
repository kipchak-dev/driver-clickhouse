<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests\Integration;

use DateTimeImmutable;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseConnectionException;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseException;
use Kipchak\Driver\ClickHouse\Library\ClickHouseClient;
use Kipchak\Driver\ClickHouse\Library\ConnectionConfig;
use Kipchak\Driver\ClickHouse\Library\Transport;
use Kipchak\Driver\Http\Library\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Against ClickHouse in Docker: start it with tests/Integration/start-services.sh. Skipped when it is not running.
 */
final class ClickHouseServerTest extends TestCase
{
    private const string HTTP = 'http://127.0.0.1:58123';
    private const string HTTPS = 'https://127.0.0.1:58443';
    private const string CERTS = __DIR__ . '/.certs';
    private const string COMPOSE = __DIR__ . '/compose.yaml';

    /** @var list<string> */
    private array $logged = [];

    public static function setUpBeforeClass(): void
    {
        if (@file_get_contents(self::HTTP . '/ping') !== "Ok.\n") {
            self::markTestSkipped('ClickHouse is not running; start it with tests/Integration/start-services.sh');
        }
    }

    protected function setUp(): void
    {
        $this->logged = [];
        $this->client()->execute('DROP TABLE IF EXISTS events');
        $this->client()->execute(<<<'SQL'
            CREATE TABLE events (
                id UInt64,
                user String,
                at DateTime64(6, 'UTC'),
                amount Decimal(18, 4),
                tags Array(String),
                attributes Map(String, String),
                score Nullable(Float64),
                active Bool
            ) ENGINE = MergeTree ORDER BY id
            SQL);
    }

    private function client(array $overrides = [], bool $cache = false): ClickHouseClient
    {
        $config = ConnectionConfig::fromArray('default', $overrides + [
            'hosts' => self::HTTP,
            'username' => 'kipchak',
            'password' => 'kipchak-password',
            'timeout' => 10,
        ]);
        $logger = new class ($this->logged) extends AbstractLogger {
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ' ' . ($context['host'] ?? '');
            }
        };

        return new ClickHouseClient(new Transport(new Http(), $config, $logger), $config, $cache ? new ArrayAdapter() : null);
    }

    private static function rows(int $count, int $from = 1): \Generator
    {
        for ($i = $from; $i < $from + $count; $i++) {
            yield [
                'id' => $i,
                'user' => 'user-' . ($i % 10),
                'at' => new DateTimeImmutable('@' . (1700000000 + $i) . '.250000'),
                'amount' => '12.3456',
                'tags' => ['a', "it's"],
                'attributes' => ['plan' => 'pro'],
                'score' => $i % 2 === 0 ? null : 0.5,
                'active' => $i % 3 === 0,
            ];
        }
    }

    public function testParametersRoundTrip(): void
    {
        $tricky = "tab\there, new\nline, quote ' and \\ backslash, \0 zero, ünïcödé";
        $row = $this->client()->first(<<<'SQL'
            SELECT
                {s:String} AS s,
                {n:Nullable(String)} AS n,
                {i:Int64} AS i,
                {f:Float64} AS f,
                {b:Bool} AS b,
                {t:DateTime64(6, 'UTC')} AS t,
                {d:DateTime('UTC')} AS d,
                {day:Date} AS day,
                {list:Array(String)} AS list,
                {map:Map(String, Array(UInt8))} AS map,
                {u:UUID} AS u,
                {table:Identifier} AS ignored
            FROM system.one
            SQL, [
            's' => $tricky,
            'n' => null,
            'i' => PHP_INT_MIN,
            'f' => 0.1,
            'b' => true,
            't' => new DateTimeImmutable('2026-10-29 08:30:00.123456 +01:00'),
            'd' => new DateTimeImmutable('2026-10-29 07:30:00 UTC'),
            'day' => '2026-10-29',
            'list' => [$tricky, ''],
            'map' => ['k' => [1, 2]],
            'u' => '61f0c404-5cb3-11e7-907b-a6006ad3dba0',
            'table' => 'dummy',
        ]);

        $this->assertSame($tricky, $row['s']);
        $this->assertNull($row['n']);
        $this->assertSame(PHP_INT_MIN, $row['i']);
        $this->assertSame(0.1, $row['f']);
        $this->assertTrue($row['b']);
        $this->assertSame('2026-10-29 07:30:00.123456', $row['t']);
        $this->assertSame('2026-10-29 07:30:00', $row['d']);
        $this->assertSame('2026-10-29', $row['day']);
        $this->assertSame([$tricky, ''], $row['list']);
        $this->assertSame(['k' => [1, 2]], $row['map']);
        $this->assertSame(0, $row['ignored']);
    }

    public function testParametersCannotInjectSql(): void
    {
        $client = $this->client();
        $client->insert('events', self::rows(3));

        $count = $client->value('SELECT count() FROM events WHERE user = {user:String}', ['user' => "x' OR '1'='1"]);

        $this->assertSame(0, $count);
    }

    public function testNumbersKeepTheirPrecision(): void
    {
        $row = $this->client()->first('SELECT toUInt64(18446744073709551615) AS big, toInt128(-5) AS small, toInt256(2) AS huge, toDecimal128(\'12345678901234567890.123\', 3) AS money, count() AS c FROM system.one');

        $this->assertSame('18446744073709551615', $row['big']);
        $this->assertSame(-5, $row['small']);
        $this->assertSame(2, $row['huge']);
        $this->assertSame('12345678901234567890.123', $row['money']);
        $this->assertSame(1, $row['c']);
    }

    public function testInsertAndSelect(): void
    {
        $client = $this->client(['insert_batch_size' => 400]);

        $summary = $client->insert('events', self::rows(1000));
        $this->assertSame(1000, $summary->writtenRows, 'Three batches, one total');

        $result = $client->select('SELECT * FROM events WHERE id IN {ids:Array(UInt64)} ORDER BY id', ['ids' => [1, 2]]);
        $this->assertSame([1, 2], $result->column('id'));
        $this->assertSame([
            'id' => 1,
            'user' => 'user-1',
            'at' => '2023-11-14 22:13:21.250000',
            'amount' => '12.3456',
            'tags' => ['a', "it's"],
            'attributes' => ['plan' => 'pro'],
            'score' => 0.5,
            'active' => false,
        ], $result->first());
        $this->assertNull($result->rows[1]['score']);
        $this->assertSame('Decimal(18, 4)', $result->types['amount']);

        // A lower bound: ClickHouse stops counting once it has read enough parts in order.
        $page = $client->select('SELECT id FROM events ORDER BY id LIMIT 10');
        $this->assertGreaterThanOrEqual(400, $page->rowsBeforeLimit);
        $this->assertGreaterThan(0, $page->rowsRead);
    }

    public function testInsertsWithAndWithoutCompression(): void
    {
        $this->client(['compression' => true])->insert('events', self::rows(500));
        $this->client(['compression' => false])->insert('events', self::rows(500, 501));

        $this->assertSame(1000, $this->client(['compression' => false])->value('SELECT count() FROM events'));
        $this->assertSame(500500, $this->client()->value('SELECT sum(id) FROM events'));
    }

    public function testMissingColumnsTakeTheirDefaults(): void
    {
        $client = $this->client();
        $client->insert('events', [['id' => 7]]);

        $this->assertSame(['id' => 7, 'user' => '', 'score' => null], $client->first('SELECT id, user, score FROM events'));
    }

    public function testInsertsDatesIntoEveryDateTimeColumn(): void
    {
        $client = $this->client();
        $client->execute("CREATE TABLE dates (id UInt8, plain DateTime, zoned DateTime('Asia/Dubai'), milli DateTime64(3), micro DateTime64(6, 'America/New_York')) ENGINE = Memory");

        // DateTime refuses a fractional Unix timestamp, so dates with microseconds must still reach it.
        $withMicroseconds = new DateTimeImmutable('2026-10-31 13:14:00.407722', new \DateTimeZone('Asia/Dubai'));
        $wholeSecond = new DateTimeImmutable('@1700000000');
        $client->insert('dates', [
            ['id' => 1, 'plain' => $withMicroseconds, 'zoned' => $withMicroseconds, 'milli' => $withMicroseconds, 'micro' => $withMicroseconds],
            ['id' => 2, 'plain' => $wholeSecond, 'zoned' => $wholeSecond, 'milli' => $wholeSecond, 'micro' => $wholeSecond],
        ]);

        $rows = $client->select('SELECT toUnixTimestamp(plain) AS plain, toUnixTimestamp(zoned) AS zoned, toUnixTimestamp64Milli(milli) AS milli, toUnixTimestamp64Micro(micro) AS micro FROM dates ORDER BY id')->rows();
        $client->execute('DROP TABLE dates');

        $this->assertSame(['plain' => 1793438040, 'zoned' => 1793438040, 'milli' => 1793438040407, 'micro' => 1793438040407722], $rows[0]);
        $this->assertSame(['plain' => 1700000000, 'zoned' => 1700000000, 'milli' => 1700000000000, 'micro' => 1700000000000000], $rows[1]);
    }

    public function testDateParametersWithMicroseconds(): void
    {
        // As in a "last 7 days" query: DateTimeImmutable('-7 days') has microseconds, which DateTime refuses.
        $at = new DateTimeImmutable('2026-10-31 13:14:00.407722', new \DateTimeZone('Asia/Dubai'));

        $this->assertSame(
            ['plain' => 1793438040, 'zoned' => 1793438040, 'micro' => '1793438040407722', 'days' => [1793438040]],
            $this->client()->first(
                "SELECT toUnixTimestamp({plain:DateTime}) AS plain, toUnixTimestamp({zoned:DateTime('Asia/Dubai')}) AS zoned,
                        toString(toUnixTimestamp64Micro({micro:DateTime64(6)})) AS micro, arrayMap(d -> toUnixTimestamp(d), {days:Array(DateTime)}) AS days",
                ['plain' => $at, 'zoned' => $at, 'micro' => $at, 'days' => [$at]],
            )
        );
    }

    public function testAsyncInsert(): void
    {
        $summary = $this->client()->insert('events', self::rows(10), ['async_insert' => 1, 'wait_for_async_insert' => 1]);

        $this->assertNotSame('', $summary->queryId);
        $this->assertSame(10, $this->client()->value('SELECT count() FROM events'));
    }

    public function testEachStreamsLargeResultsInLittleMemory(): void
    {
        $before = memory_get_usage();
        $count = 0;
        $sum = 0;
        foreach ($this->client()->each('SELECT number, toString(number) AS s FROM numbers(1000000)') as $row) {
            $count++;
            $sum += $row['number'];
        }

        $this->assertSame(1000000, $count);
        $this->assertSame(499999500000, $sum);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_usage() - $before);
    }

    public function testStoppingAStreamEarlyCancelsTheQuery(): void
    {
        $client = $this->client();
        $queryId = 'kipchak-stop-' . bin2hex(random_bytes(4));

        foreach ($client->each('SELECT number, sleepEachRow(0.01) FROM numbers(100000)', settings: ['max_block_size' => 10], queryId: $queryId) as $row) {
            break;
        }

        $running = 1;
        for ($i = 0; $i < 50 && $running > 0; $i++) {
            usleep(100000);
            $running = $client->value('SELECT count() FROM system.processes WHERE query_id = {id:String}', ['id' => $queryId]);
        }
        $this->assertSame(0, $running, 'ClickHouse stopped the query when the stream was closed');
    }

    public function testErrorsPartWayThroughAStream(): void
    {
        $rows = [];
        try {
            foreach ($this->client()->each('SELECT number, throwIf(number = 3) FROM numbers(10)', settings: ['max_block_size' => 1]) as $row) {
                $rows[] = $row['number'];
            }
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertSame(395, $exception->errorCode);
            $this->assertSame('FUNCTION_THROW_IF_VALUE_IS_NON_ZERO', $exception->errorName);
        }
        $this->assertSame([0, 1, 2], $rows);
    }

    public function testCancelARunningQuery(): void
    {
        $client = $this->client();
        $queryId = 'kipchak-cancel-' . bin2hex(random_bytes(4));
        $stream = $client->each('SELECT number, sleepEachRow(0.05) FROM numbers(1000)', settings: ['max_block_size' => 1], queryId: $queryId);
        $stream->current();

        $client->cancel($queryId);

        try {
            foreach ($stream as $row) {
            }
            $this->fail('Expected the query to be cancelled');
        } catch (ClickHouseException $exception) {
            $this->assertSame('QUERY_WAS_CANCELLED', $exception->errorName);
        }
    }

    public function testErrors(): void
    {
        $client = $this->client();

        try {
            $client->select('SELECT * FROM missing_table');
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertSame(60, $exception->errorCode);
            $this->assertSame('UNKNOWN_TABLE', $exception->errorName);
            $this->assertSame(404, $exception->httpStatus);
            $this->assertNotNull($exception->queryId);
        }

        try {
            $client->execute('SELEC 1');
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertSame('SYNTAX_ERROR', $exception->errorName);
        }

        try {
            $client->value('SELECT {x:UInt8}', ['x' => 'not a number']);
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertSame('BAD_QUERY_PARAMETER', $exception->errorName);
        }
    }

    public function testWrongPasswordIsNotAConnectionError(): void
    {
        try {
            $this->client(['password' => 'wrong'])->value('SELECT 1');
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertNotInstanceOf(ClickHouseConnectionException::class, $exception);
            $this->assertSame('AUTHENTICATION_FAILED', $exception->errorName);
        }
        $this->assertFalse($this->client(['password' => 'wrong'])->health());
        $this->assertTrue($this->client()->health());
    }

    public function testExecuteAndSummary(): void
    {
        $client = $this->client();
        $client->insert('events', self::rows(100));
        $client->execute('DROP TABLE IF EXISTS events_copy');
        $client->execute('CREATE TABLE events_copy AS events');

        $summary = $client->execute('INSERT INTO events_copy SELECT * FROM events WHERE id <= {max:UInt64}', ['max' => 40]);

        $this->assertSame(40, $summary->writtenRows);
        $this->assertSame(40, $client->value('SELECT count() FROM events_copy'));
        $client->execute('DROP TABLE events_copy');
    }

    public function testQueryIdsAreUsed(): void
    {
        $result = $this->client()->select('SELECT queryID() AS id', queryId: 'kipchak-mine');

        $this->assertSame('kipchak-mine', $result->queryId);
        $this->assertSame('kipchak-mine', $result->value());
    }

    public function testSettingsApply(): void
    {
        $client = $this->client(['settings' => ['max_result_rows' => 5, 'result_overflow_mode' => 'throw']]);

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('TOO_MANY_ROWS_OR_BYTES');
        $client->select('SELECT number FROM numbers(100)', settings: ['max_block_size' => 1]);
    }

    public function testCache(): void
    {
        $client = $this->client(['cache' => 'memory'], cache: true);
        $client->insert('events', self::rows(5));

        $this->assertSame(5, $client->select('SELECT count() FROM events', cacheTtl: 60)->value());
        $client->insert('events', self::rows(5, 6));
        $cached = $client->select('SELECT count() FROM events', cacheTtl: 60);

        $this->assertTrue($cached->cached);
        $this->assertSame(5, $cached->value(), 'Inserts do not clear the cache; the TTL decides');
        $this->assertSame(10, $client->value('SELECT count() FROM events'));
    }

    public function testHttps(): void
    {
        $client = $this->client(['hosts' => self::HTTPS, 'tls' => ['ca' => self::CERTS . '/ca.pem']]);

        $this->assertTrue($client->health());
        $client->insert('events', self::rows(2000));
        $this->assertSame(2000, iterator_count($client->each('SELECT id FROM events')));
    }

    public function testHttpsRefusesAnUntrustedCertificate(): void
    {
        $this->expectException(ClickHouseConnectionException::class);
        $this->client(['hosts' => self::HTTPS, 'tls' => ['ca' => self::CERTS . '/other-ca.pem']])->value('SELECT 1');
    }

    public function testFailoverToTheNextHost(): void
    {
        $client = $this->client(['hosts' => ['http://127.0.0.1:1', self::HTTP]]);

        $this->assertSame(1, $client->value('SELECT 1'));
        $client->insert('events', self::rows(3));
        foreach ($client->each('SELECT id FROM events') as $row) {
        }

        $this->assertSame(['warning http://127.0.0.1:1'], $this->logged, 'The refused host is skipped once marked down');
        $this->assertSame(3, $client->value('SELECT count() FROM events'));
    }

    public function testWritesFailOverWhenTheConnectionIsRefused(): void
    {
        $client = $this->client(['hosts' => ['http://127.0.0.1:1', self::HTTP]]);

        $this->assertSame(3, $client->insert('events', self::rows(3))->writtenRows);
        $this->assertSame(['warning http://127.0.0.1:1'], $this->logged);
    }

    public function testTimeoutsAreNotRetried(): void
    {
        $client = $this->client(['hosts' => [self::HTTP, 'http://localhost:58123'], 'timeout' => 1]);

        try {
            $client->value('SELECT sleep(2)');
            $this->fail('Expected a timeout');
        } catch (ClickHouseConnectionException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
        $this->assertSame([], $this->logged, 'The query may still be running, so it was not sent to another host');
    }

    public function testStreamTimeoutIsPerPart(): void
    {
        $client = $this->client(['timeout' => 1]);

        $count = iterator_count($client->each('SELECT number, sleepEachRow(0.3) FROM numbers(8)', settings: ['max_block_size' => 1]));
        $this->assertSame(8, $count, 'A 2.4 second stream is fine when each part arrives within a second');

        $this->expectException(ClickHouseConnectionException::class);
        iterator_count($client->each('SELECT sleep(2)'));
    }

    public function testSurvivesARestart(): void
    {
        $client = $this->client();
        $this->assertTrue($client->health());

        exec('docker compose -f ' . escapeshellarg(self::COMPOSE) . ' restart clickhouse 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        for ($i = 0; $i < 60 && @file_get_contents(self::HTTP . '/ping') !== "Ok.\n"; $i++) {
            usleep(500000);
        }

        $this->assertSame(1, $client->value('SELECT 1'), 'The same client works after ClickHouse restarts');
    }
}
