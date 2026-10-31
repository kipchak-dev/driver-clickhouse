<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Library;

use Generator;
use InvalidArgumentException;
use JsonException;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseConnectionException;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseException;
use Kipchak\Driver\ClickHouse\Query\Identifier;
use Kipchak\Driver\ClickHouse\Query\Parameter;
use Kipchak\Driver\ClickHouse\Result\Result;
use Kipchak\Driver\ClickHouse\Result\Summary;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A client for one ClickHouse connection: queries, streamed results, batched inserts and statements.
 *
 * Values go in {name:Type} placeholders and the $params array, never into the SQL string:
 *
 *   $clickhouse->select('SELECT count() FROM events WHERE user_id = {user:UInt64}', ['user' => $id]);
 *
 * Holds no per-request state, so one instance is safe to share across requests in FrankenPHP worker mode.
 */
final class ClickHouseClient
{
    private const int JSON_OUT = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const int JSON_IN = JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING;

    /**
     * Settings for statements that return rows: integers unquoted (those too large for PHP come back as strings),
     * decimals quoted so no precision is lost, and a read-only query cancelled if the client goes away.
     */
    private const array READ_DEFAULTS = [
        'output_format_json_quote_64bit_integers' => 0,
        'output_format_json_quote_decimals' => 1,
        'cancel_http_readonly_queries_on_client_close' => 1,
    ];

    /**
     * Settings for inserts: dates with microseconds are sent as ISO 8601 in UTC, which DateTime columns only read
     * with best_effort parsing (they refuse a fractional Unix timestamp).
     */
    private const array INSERT_DEFAULTS = [
        'date_time_input_format' => 'best_effort',
    ];

    public function __construct(
        private readonly Transport $transport,
        private readonly ConnectionConfig $config,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * Runs a query and returns all its rows. For results too large to hold in memory, use each().
     *
     * @param array<string, mixed> $params Values for the {name:Type} placeholders in the SQL.
     * @param array<string, scalar> $settings ClickHouse settings for this query, such as max_execution_time.
     * @param int|null $cacheTtl Seconds to cache the result in the connection's cache; null asks ClickHouse.
     * @throws ClickHouseException
     * @throws ClickHouseConnectionException
     */
    public function select(string $sql, array $params = [], array $settings = [], ?int $cacheTtl = null, ?string $queryId = null): Result
    {
        $cacheItem = null;
        if ($cacheTtl !== null) {
            $cacheItem = $this->cacheItem($sql, $params, $settings);
            if ($cacheItem->isHit()) {
                return Result::fromCache($cacheItem->get());
            }
        }

        $queryId ??= self::newQueryId();
        $response = $this->transport->post(
            $sql,
            $this->query($settings, $params, $queryId, 'JSON', read: true, sql: $sql),
            $queryId,
            readOnly: true,
        );
        $queryId = $response->header('X-ClickHouse-Query-Id') ?: $queryId;

        try {
            $json = json_decode($response->body(), true, 512, self::JSON_IN);
        } catch (JsonException $exception) {
            throw new ClickHouseException(
                '[ClickHouse] The result is not JSON. select() sets the output format itself; remove any FORMAT clause from the query, or use execute(). ' . $exception->getMessage(),
                queryId: $queryId,
                previous: $exception,
            );
        }
        if (!is_array($json)) {
            throw new ClickHouseException('[ClickHouse] The result is not a JSON object.', queryId: $queryId);
        }
        if (isset($json['exception'])) {
            throw ClickHouseException::fromResponse((string) json_encode(['exception' => $json['exception']]), $response->status(), $queryId);
        }

        $result = Result::fromJson($json, $queryId);
        if ($cacheItem !== null && $this->cache !== null) {
            $this->cache->save($cacheItem->set($result->toArray())->expiresAfter($cacheTtl));
        }

        return $result;
    }

    /**
     * The first row, or null when there are none.
     *
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = [], array $settings = [], ?int $cacheTtl = null): ?array
    {
        return $this->select($sql, $params, $settings, $cacheTtl)->first();
    }

    /**
     * The first column of the first row, such as the number from SELECT count(), or null when there are no rows.
     */
    public function value(string $sql, array $params = [], array $settings = [], ?int $cacheTtl = null): mixed
    {
        return $this->select($sql, $params, $settings, $cacheTtl)->value();
    }

    /**
     * Streams a query's rows one at a time, as ClickHouse sends them, so a result of any size uses little memory.
     *
     * The timeout limits the wait for each part of the result, not the whole stream. Stopping early (breaking
     * out of the loop) closes the connection and ClickHouse cancels the query. An error part-way through throws
     * after the rows before it have been yielded.
     *
     * @param array<string, mixed> $params
     * @param array<string, scalar> $settings
     * @return Generator<int, array<string, mixed>>
     * @throws ClickHouseException
     * @throws ClickHouseConnectionException
     */
    public function each(string $sql, array $params = [], array $settings = [], ?string $queryId = null): Generator
    {
        $queryId ??= self::newQueryId();
        $response = $this->transport->post(
            $sql,
            $this->query($settings, $params, $queryId, 'JSONEachRowWithProgress', read: true, sql: $sql),
            $queryId,
            readOnly: true,
            stream: true,
        );
        $queryId = $response->header('X-ClickHouse-Query-Id') ?: $queryId;
        $body = $response->toPsrResponse()->getBody();

        try {
            $buffer = '';
            while (true) {
                $chunk = $body->eof() ? '' : $body->read(65536);
                if ($chunk === '') {
                    if ($body->eof()) {
                        break;
                    }
                    if ($body->getMetadata('timed_out') === true) {
                        throw new ClickHouseConnectionException(
                            '[ClickHouse] No part of the result arrived within ' . $this->config->timeout . ' seconds.',
                            queryId: $queryId,
                        );
                    }
                    continue;
                }
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 1);
                    if (($row = $this->streamedRow($line, $queryId)) !== null) {
                        yield $row;
                    }
                }
            }
            if (($row = $this->streamedRow($buffer, $queryId)) !== null) {
                yield $row;
            }
        } catch (\RuntimeException $exception) {
            if ($exception instanceof ClickHouseException) {
                throw $exception;
            }
            throw new ClickHouseConnectionException(
                '[ClickHouse] The result stream broke: ' . $exception->getMessage(),
                queryId: $queryId,
                previous: $exception,
            );
        } finally {
            $body->close();
        }
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Inserts rows, each an array (or object) of column => value, in batches of insert_batch_size rows, one
     * INSERT per batch. Columns missing from a row get their default. Each batch is atomic for a MergeTree table;
     * a failure leaves earlier batches inserted.
     *
     * DateTimeInterface values become Unix timestamps, or ISO 8601 in UTC when they have microseconds (kept by
     * DateTime64, dropped by DateTime), and enums their values.
     *
     * For many small inserts from concurrent requests, pass ['async_insert' => 1, 'wait_for_async_insert' => 1]
     * so ClickHouse batches them on the server.
     *
     * @param iterable<array<string, mixed>|object> $rows
     * @param array<string, scalar> $settings
     * @throws ClickHouseException
     * @throws ClickHouseConnectionException
     */
    public function insert(string $table, iterable $rows, array $settings = [], ?string $queryId = null): Summary
    {
        $statement = 'INSERT INTO ' . Identifier::quote($table) . " FORMAT JSONEachRow\n";
        $queryId ??= self::newQueryId();
        $summary = new Summary($queryId);
        $batch = '';
        $count = 0;
        $batches = 0;

        foreach ($rows as $row) {
            if (!is_array($row) && !is_object($row)) {
                throw new InvalidArgumentException('Each row to insert must be an array or object of column => value; got ' . get_debug_type($row) . '.');
            }
            $batch .= json_encode(is_array($row) ? Parameter::forInsert($row) : $row, self::JSON_OUT) . "\n";
            if (++$count === $this->config->insertBatchSize) {
                $summary = $summary->plus($this->sendInsert($statement . $batch, $settings, self::batchId($queryId, ++$batches)));
                $batch = '';
                $count = 0;
            }
        }
        if ($count > 0) {
            $summary = $summary->plus($this->sendInsert($statement . $batch, $settings, self::batchId($queryId, ++$batches)));
        }

        return $summary;
    }

    /**
     * Runs a statement that returns no rows: CREATE, ALTER, DROP, TRUNCATE, INSERT ... SELECT, OPTIMIZE, ...
     *
     * @param array<string, mixed> $params
     * @param array<string, scalar> $settings
     * @throws ClickHouseException
     * @throws ClickHouseConnectionException
     */
    public function execute(string $sql, array $params = [], array $settings = [], ?string $queryId = null): Summary
    {
        $queryId ??= self::newQueryId();
        $response = $this->transport->post($sql, $this->query($settings, $params, $queryId, sql: $sql), $queryId, readOnly: false);

        return Summary::fromHeader($response->header('X-ClickHouse-Query-Id') ?: $queryId, $response->header('X-ClickHouse-Summary') ?: null);
    }

    /**
     * Asks ClickHouse to stop a running query, by the query ID passed to (or returned from) another method.
     */
    public function cancel(string $queryId): void
    {
        $this->execute('KILL QUERY WHERE query_id = {id:String} ASYNC', ['id' => $queryId]);
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    /**
     * Whether ClickHouse answers a query with this connection's credentials. Never throws.
     */
    public function health(): bool
    {
        try {
            return $this->value('SELECT 1') === 1;
        } catch (ClickHouseException) {
            return false;
        }
    }

    public function serverVersion(): string
    {
        return (string) $this->value('SELECT version()');
    }

    /**
     * Forgets every cached result for this connection.
     */
    public function clearCache(): void
    {
        $this->cache?->clear();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function sendInsert(string $body, array $settings, string $queryId): Summary
    {
        $response = $this->transport->post($body, $this->query($settings, [], $queryId, insert: true), $queryId, readOnly: false);

        return Summary::fromHeader($response->header('X-ClickHouse-Query-Id') ?: $queryId, $response->header('X-ClickHouse-Summary') ?: null);
    }

    /**
     * The query string: the driver's defaults, then the connection's settings, then this call's, then the
     * parameters, query ID and output format.
     *
     * @return array<string, scalar>
     */
    private function query(array $settings, array $params, string $queryId, ?string $format = null, bool $read = false, bool $insert = false, string $sql = ''): array
    {
        $query = [...($read ? self::READ_DEFAULTS : []), ...($insert ? self::INSERT_DEFAULTS : []), ...$this->config->settings, ...$settings];
        foreach ($query as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                throw new InvalidArgumentException('Settings must map ClickHouse setting names to scalar values.');
            }
            if (str_starts_with($name, 'param_') || in_array($name, ['query', 'query_id', 'database', 'default_format', 'user', 'password'], true)) {
                throw new InvalidArgumentException('"' . $name . '" is not a ClickHouse setting the driver lets you pass; use the method\'s own arguments.');
            }
        }

        $query = [...$query, ...Parameter::toQuery($params, $sql), 'query_id' => $queryId];
        if ($format !== null) {
            $query['default_format'] = $format;
        }

        return $query;
    }

    /**
     * Decodes one line of JSONEachRowWithProgress: returns a row, throws an error, or skips progress and metadata.
     *
     * @return array<string, mixed>|null
     */
    private function streamedRow(string $line, string $queryId): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            $message = json_decode($line, true, 512, self::JSON_IN);
        } catch (JsonException $exception) {
            throw new ClickHouseException(
                '[ClickHouse] A streamed line is not JSON. each() sets the output format itself; remove any FORMAT clause from the query. ' . $exception->getMessage(),
                queryId: $queryId,
                previous: $exception,
            );
        }

        if (isset($message['exception'])) {
            throw ClickHouseException::fromResponse((string) json_encode(['exception' => $message['exception']]), 200, $queryId);
        }

        return isset($message['row']) && is_array($message['row']) ? $message['row'] : null;
    }

    private function cacheItem(string $sql, array $params, array $settings): \Psr\Cache\CacheItemInterface
    {
        if ($this->cache === null) {
            throw new InvalidArgumentException(
                'ClickHouse connection "' . $this->config->name . '" has no cache; set its "cache" to "memory", "file" or a container key to use cacheTtl.'
            );
        }

        $key = hash('sha256', serialize([$this->config->database, $sql, Parameter::toQuery($params, $sql), $settings]));

        return $this->cache->getItem($key);
    }

    private static function batchId(string $queryId, int $batch): string
    {
        return $batch === 1 ? $queryId : $queryId . '-' . $batch;
    }

    private static function newQueryId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
