<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests\Library;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
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
 * The client against Laravel's HTTP fake: what it sends and how it reads answers. Behaviour against a real server is
 * in tests/Integration.
 */
final class ClickHouseClientTest extends TestCase
{
    private Http $http;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->http = new Http();
        $this->logged = [];
    }

    private function client(array $config = [], bool $cache = false): ClickHouseClient
    {
        $config = ConnectionConfig::fromArray('default', $config + [
            'hosts' => 'http://ch:8123',
            'database' => 'analytics',
            'username' => 'api',
            'password' => 'secret',
        ]);
        $logger = new class ($this->logged) extends AbstractLogger {
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ' ' . $context['host'];
            }
        };

        return new ClickHouseClient(new Transport($this->http, $config, $logger), $config, $cache ? new ArrayAdapter() : null);
    }

    private static function json(array $rows, array $meta = []): array
    {
        return ['meta' => $meta, 'data' => $rows, 'rows' => count($rows), 'statistics' => ['elapsed' => 0.001, 'rows_read' => 1, 'bytes_read' => 1]];
    }

    /**
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    public function testSelectSendsTheQueryParametersAndCredentials(): void
    {
        $this->http->fake(['*' => Factory::response(self::json([['c' => 3]], [['name' => 'c', 'type' => 'UInt64']]), 200, ['X-ClickHouse-Query-Id' => 'server-id'])]);

        $result = $this->client()->select('SELECT count() c FROM events WHERE user = {user:String}', ['user' => "o'brien"], ['max_threads' => 2], queryId: 'mine');

        $this->assertSame(3, $result->value());
        $this->assertSame('server-id', $result->queryId);
        $this->http->assertSent(function (Request $request): bool {
            $query = self::query($request);

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'http://ch:8123/?')
                && $request->body() === 'SELECT count() c FROM events WHERE user = {user:String}'
                && $request->header('X-ClickHouse-User') === ['api']
                && $request->header('X-ClickHouse-Key') === ['secret']
                && $request->header('X-ClickHouse-Database') === ['analytics']
                && $query['param_user'] === "o'brien"
                && $query['max_threads'] === '2'
                && $query['query_id'] === 'mine'
                && $query['default_format'] === 'JSON'
                && $query['output_format_json_quote_64bit_integers'] === '0'
                && $query['output_format_json_quote_decimals'] === '1'
                && $query['cancel_http_readonly_queries_on_client_close'] === '1'
                && $query['enable_http_compression'] === '1';
        });
    }

    public function testSettingsOverrideInOrder(): void
    {
        $this->http->fake(['*' => Factory::response(self::json([]))]);

        $this->client(['settings' => ['max_threads' => 4, 'output_format_json_quote_decimals' => 0]])->select('SELECT 1', settings: ['max_threads' => 8]);

        $this->http->assertSent(fn (Request $request): bool => self::query($request)['max_threads'] === '8'
            && self::query($request)['output_format_json_quote_decimals'] === '0');
    }

    public function testReservedSettingsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"default_format" is not a ClickHouse setting');
        $this->client()->select('SELECT 1', settings: ['default_format' => 'CSV']);
    }

    public function testNoPasswordSendsNoKey(): void
    {
        $this->http->fake(['*' => Factory::response(self::json([]))]);

        $this->client(['password' => '', 'compression' => false])->select('SELECT 1');

        $this->http->assertSent(fn (Request $request): bool => !$request->hasHeader('X-ClickHouse-Key')
            && !array_key_exists('enable_http_compression', self::query($request)));
    }

    public function testErrorsBecomeExceptions(): void
    {
        $this->http->fake(['*' => Factory::response(
            'Code: 62. DB::Exception: Syntax error: failed at position 1. (SYNTAX_ERROR) (version 25.8.33.6 (official build))',
            400,
            ['X-ClickHouse-Exception-Code' => '62', 'X-ClickHouse-Query-Id' => 'q'],
        )]);

        try {
            $this->client()->select('SELEC 1');
            $this->fail('Expected an exception');
        } catch (ClickHouseException $exception) {
            $this->assertNotInstanceOf(ClickHouseConnectionException::class, $exception);
            $this->assertSame(62, $exception->errorCode);
            $this->assertSame('SYNTAX_ERROR', $exception->errorName);
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame('q', $exception->queryId);
        }
    }

    public function testExceptionInsideAJsonResultIsThrown(): void
    {
        $this->http->fake(['*' => Factory::response(self::json([]) + ['exception' => 'Code: 241. DB::Exception: Memory limit exceeded. (MEMORY_LIMIT_EXCEEDED) (version 25.8)'])]);

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('Memory limit exceeded');
        $this->client()->select('SELECT 1');
    }

    public function testAnotherFormatIsExplained(): void
    {
        $this->http->fake(['*' => Factory::response("1\t2\n")]);

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('remove any FORMAT clause');
        $this->client()->select('SELECT 1, 2 FORMAT TSV');
    }

    public function testCachedSelectsAskOnce(): void
    {
        $this->http->fake(['*' => Factory::response(self::json([['n' => 1]], [['name' => 'n', 'type' => 'UInt8']]))]);
        $client = $this->client(['cache' => 'memory'], cache: true);

        $first = $client->select('SELECT {n:UInt8} n', ['n' => 1], cacheTtl: 60);
        $second = $client->select('SELECT {n:UInt8} n', ['n' => 1], cacheTtl: 60);
        $other = $client->select('SELECT {n:UInt8} n', ['n' => 2], cacheTtl: 60);
        $uncached = $client->select('SELECT {n:UInt8} n', ['n' => 1]);

        $this->assertFalse($first->cached);
        $this->assertTrue($second->cached);
        $this->assertSame($first->rows, $second->rows);
        $this->assertFalse($other->cached, 'Different parameters are a different query');
        $this->assertFalse($uncached->cached);
        $this->http->assertSentCount(3);

        $client->clearCache();
        $this->assertFalse($client->select('SELECT {n:UInt8} n', ['n' => 1], cacheTtl: 60)->cached);
    }

    public function testCacheTtlNeedsACache(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no cache');
        $this->client()->select('SELECT 1', cacheTtl: 60);
    }

    public function testInsertSendsBatchesOfJsonLines(): void
    {
        $this->http->fake(['*' => Factory::response('', 200, ['X-ClickHouse-Summary' => '{"written_rows":"2","written_bytes":"20"}'])]);

        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 5; $i++) {
                yield ['id' => $i, 'at' => new \DateTimeImmutable('@1700000000'), 'note' => "line\nbreak"];
            }
        })();
        $summary = $this->client(['insert_batch_size' => 2, 'compression' => false])->insert('analytics.events', $rows, ['async_insert' => 1], queryId: 'ins');

        $this->assertSame('ins', $summary->queryId);
        $this->assertSame(6, $summary->writtenRows, 'The summaries of the three batches are added up');
        $this->http->assertSentCount(3);
        $this->http->assertSentInOrder([
            fn (Request $r): bool => self::query($r)['query_id'] === 'ins' && substr_count($r->body(), "\n") === 3,
            fn (Request $r): bool => self::query($r)['query_id'] === 'ins-2',
            fn (Request $r): bool => self::query($r)['query_id'] === 'ins-3'
                && self::query($r)['async_insert'] === '1'
                && !array_key_exists('default_format', self::query($r))
                && $r->body() === "INSERT INTO `analytics`.`events` FORMAT JSONEachRow\n{\"id\":5,\"at\":\"1700000000\",\"note\":\"line\\nbreak\"}\n",
        ]);
    }

    public function testLargeInsertsAreCompressed(): void
    {
        $this->http->fake(['*' => Factory::response('')]);

        $this->client()->insert('events', array_fill(0, 100, ['note' => str_repeat('x', 100)]));

        $this->http->assertSent(fn (Request $r): bool => $r->header('Content-Encoding') === ['gzip']
            && str_starts_with((string) gzdecode($r->body()), "INSERT INTO `events` FORMAT JSONEachRow\n"));
    }

    public function testNothingToInsertSendsNothing(): void
    {
        $this->http->fake();

        $this->assertSame(0, $this->client()->insert('events', [])->writtenRows);
        $this->http->assertNothingSent();
    }

    public function testRowsMustBeArraysOrObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('got string');
        $this->client()->insert('events', ['not a row']);
    }

    public function testExecuteReturnsTheSummary(): void
    {
        $this->http->fake(['*' => Factory::response('', 200, ['X-ClickHouse-Summary' => '{"written_rows":"10"}', 'X-ClickHouse-Query-Id' => 'x'])]);

        $summary = $this->client()->execute('INSERT INTO a SELECT * FROM b WHERE day = {day:Date}', ['day' => '2026-10-29']);

        $this->assertSame(10, $summary->writtenRows);
        $this->assertSame('x', $summary->queryId);
        $this->http->assertSent(fn (Request $r): bool => self::query($r)['param_day'] === '2026-10-29'
            && !array_key_exists('cancel_http_readonly_queries_on_client_close', self::query($r)));
    }

    public function testCancelKillsTheQuery(): void
    {
        $this->http->fake(['*' => Factory::response('')]);

        $this->client()->cancel('slow-one');

        $this->http->assertSent(fn (Request $r): bool => str_starts_with($r->body(), 'KILL QUERY WHERE query_id = {id:String}')
            && self::query($r)['param_id'] === 'slow-one');
    }

    public function testReadsFailOverToTheNextHost(): void
    {
        $this->http->fake([
            'ch1:8123/*' => Factory::failedConnection('Connection reset'),
            'ch2:8123/*' => Factory::response(self::json([['x' => 1]])),
        ]);
        $client = $this->client(['hosts' => ['http://ch1:8123', 'http://ch2:8123']]);

        $this->assertSame(1, $client->value('SELECT 1 x'));
        $this->assertSame(['warning http://ch1:8123'], $this->logged);

        // The failed host is skipped until host_retry_after has passed.
        $client->value('SELECT 1 x');
        $this->assertCount(3, $this->http->recorded());
    }

    public function testWritesDoNotFailOverAfterAnUnknownConnectionError(): void
    {
        // The fake carries no cURL error number, so the statement may have reached the first host.
        $this->http->fake([
            'ch1:8123/*' => Factory::failedConnection('Connection reset'),
            'ch2:8123/*' => Factory::response(''),
        ]);

        try {
            $this->client(['hosts' => ['http://ch1:8123', 'http://ch2:8123']])->execute('INSERT INTO a VALUES (1)');
            $this->fail('Expected a connection exception');
        } catch (ClickHouseConnectionException $exception) {
            $this->assertStringContainsString('Connection reset', $exception->getMessage());
        }
        $this->assertCount(1, $this->http->recorded());
    }

    public function testHealthNeverThrows(): void
    {
        $this->http->fake(['*' => Factory::failedConnection()]);

        $this->assertFalse($this->client()->health());
    }
}
