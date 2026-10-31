# ClickHouse Driver for Kipchak

A ClickHouse driver for Kipchak: run analytics queries, stream large results, and insert events in batches from
your Kipchak API. ClickHouse pairs naturally with the Kafka driver: ingest with Kafka, query with ClickHouse.

* **Queries**: `select()` returns rows with their column types; `value()` and `first()` for single answers.
  Values go in `{name:Type}` placeholders and travel separately from the SQL, so they cannot change the query.
* **Streaming**: `each()` yields rows as ClickHouse sends them, so a result of any size uses little memory.
  Stopping early cancels the query on the server.
* **Inserts**: `insert()` sends rows in batches as JSON lines, gzip-compressed, with server-side batching
  (`async_insert`) for many small writes.
* **Statements**: `execute()` for DDL and `INSERT ... SELECT`; `cancel()` stops a running query by its ID.
* **Production settings**: several hosts with failover, timeouts, HTTPS with CA files and client certificates,
  and per-query settings. Nothing is held open between requests, so it is safe in FrankenPHP's long-lived workers.
* **Built on Kipchak's drivers**: requests go through the HTTP driver, warnings to the Logger driver, and
  results can be cached in worker memory, with the Filecache driver or with the Valkey driver.

Uses ClickHouse's HTTP interface, so no PHP extension is needed. Tested against ClickHouse 25.8.

#### Composer Package

`kipchak/driver-clickhouse`

## Installation

```bash
composer require kipchak/driver-clickhouse
```

Initialise it in `drivers/drivers.php`, after the Logger and HTTP drivers (and after Filecache or Valkey, if you
cache results with them):

```php
use Kipchak\Driver\ClickHouse\ClickHouse;

ClickHouse::initialise($container);
```

## Configuration

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.clickhouse.php`:

```php
return [
    'connections' => [
        'default' => [
            'hosts' => [env('CLICKHOUSE_URL', 'http://clickhouse:8123')],
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USER', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
        ],
    ],
];
```

| Key | Default | Meaning |
|---|---|---|
| `hosts` | required | One or more `http://` or `https://` URLs (ports 8123 and 8443 by default), tried in order |
| `database` | `default` | The database queries run in |
| `username`, `password` | `default`, empty | Sent in headers, never in the URL |
| `timeout` | `30` | Seconds for a query; for `each()`, the longest wait for each part of the result |
| `connect_timeout` | `2` | Seconds to connect |
| `host_retry_after` | `30` | Seconds to skip a host that could not be reached |
| `compression` | `true` | gzip for results and for inserts larger than 1 KB |
| `insert_batch_size` | `10000` | Rows per `INSERT` when `insert()` is given more |
| `settings` | none | [ClickHouse settings](https://clickhouse.com/docs/operations/settings/settings) sent with every query, such as `max_execution_time` |
| `tls.ca` | system CAs | CA file to verify ClickHouse's certificate |
| `tls.verify` | `true` | Set `false` only for local development |
| `tls.cert`, `tls.key` | none | Client certificate and key |
| `cache` | none | For `select()` with a `cacheTtl`: `memory`, `file` (the Filecache driver) or a container key such as `drivers.cache.valkey.cache` |

Add more entries under `connections` for more clusters or databases, and fetch them by name:
`ClickHouse::get('archive')`.

## Usage

### Queries

```php
use Kipchak\Driver\ClickHouse\ClickHouse;

$clickhouse = ClickHouse::get();

$result = $clickhouse->select(
    'SELECT toDate(at) AS day, count() AS hits
     FROM events
     WHERE user_id = {user:UInt64} AND at >= {from:DateTime}
     GROUP BY day ORDER BY day',
    ['user' => $userId, 'from' => new DateTimeImmutable('-7 days')],
);

foreach ($result as $row) {
    $row['day'];    // '2026-10-29'
    $row['hits'];   // 42
}
$result->types;          // ['day' => 'Date', 'hits' => 'UInt64']
$result->column('hits'); // [40, 42, ...]
$result->rowsRead;       // rows ClickHouse read to answer
$result->queryId;

$clickhouse->value('SELECT count() FROM events');             // 1250000
$clickhouse->first('SELECT * FROM users WHERE id = {id:UInt64}', ['id' => 7]);
```

Write each value as a placeholder, `{name:Type}`, and pass it in the parameters array. ClickHouse parses it as
that type, so a value can never change the query. PHP values are sent as follows:

| PHP | ClickHouse |
|---|---|
| `null` | `NULL`, for `Nullable` types |
| `bool`, `int`, `float`, `string` | as they are (`true`/`false` for `Bool`) |
| `DateTimeInterface` | a Unix timestamp, so time zones cannot be misread: whole seconds for a `DateTime` placeholder, with microseconds for `DateTime64`. Pass `Date` values as `'Y-m-d'` strings. |
| a list | `Array`, nesting |
| an array with string keys | `Map` |
| `BackedEnum`, `Stringable` | the value, the string |

Table and column names can be placeholders too: `SELECT count() FROM {table:Identifier}`.

Results come back as PHP values. Integers that fit in a PHP `int` are ints, and larger ones (`UInt64` above
`PHP_INT_MAX`, `Int128`, `Int256`) are strings. Decimals are strings, so no precision is lost. `DateTime` values
are strings in the column's time zone; set `session_timezone` in `settings` to choose one.

`select()` sets the output format itself, so leave `FORMAT` out of the query. ClickHouse's HTTP interface runs one
statement per request.

### Streaming large results

```php
foreach ($clickhouse->each('SELECT * FROM events WHERE day = {day:Date}', ['day' => '2026-10-29']) as $row) {
    fwrite($csv, implode(',', $row) . "\n");
}
```

`each()` holds one row in memory at a time. The query is sent when the loop starts. The `timeout` limits each
wait for the next part of the result, not the whole stream, so long exports are not cut off. Breaking out of the
loop closes the connection and ClickHouse cancels the query. An error part-way through throws after the rows
before it.

### Inserting

```php
$summary = $clickhouse->insert('events', [
    ['user_id' => 7, 'action' => 'login', 'at' => new DateTimeImmutable()],
    ['user_id' => 8, 'action' => 'logout', 'at' => new DateTimeImmutable()],
]);
$summary->writtenRows;   // 2
```

Rows are arrays (or objects) of column => value; missing columns get their defaults. Dates go into `DateTime`
and `DateTime64` columns alike, the latter keeping their microseconds. `insert()` accepts any
iterable, including a generator, and sends `insert_batch_size` rows per `INSERT`. Each batch is atomic for a
MergeTree table; if one fails, earlier batches stay inserted.

ClickHouse prefers fewer, larger inserts. When many requests each insert a few rows, let ClickHouse batch them:

```php
$clickhouse->insert('events', [$event], ['async_insert' => 1, 'wait_for_async_insert' => 1]);
```

With `async_insert`, the server writes the rows with the next flush, so `writtenRows` is 0.

### Statements and cancelling

```php
$clickhouse->execute('ALTER TABLE events DELETE WHERE user_id = {user:UInt64}', ['user' => 7]);
$summary = $clickhouse->execute('INSERT INTO daily SELECT toDate(at), count() FROM events GROUP BY 1');

$result = $clickhouse->select($sql, queryId: 'report-2026-10');
$clickhouse->cancel('report-2026-10');   // from another request
```

Every method takes ClickHouse settings for that call (`settings: ['max_execution_time' => 5]`) and an optional
`queryId`; otherwise each call gets a UUID. Results, summaries and exceptions carry the query ID, so you can find
the query in `system.query_log`.

### Caching

```php
$result = $clickhouse->select($dashboardSql, $params, cacheTtl: 60);
$result->cached;   // true when it came from the cache
```

Only calls with a `cacheTtl` are cached, keyed by the SQL, parameters and settings. Writes do not clear the cache;
the TTL decides. `clearCache()` forgets the connection's cached results.

### Errors

* `ClickHouseConnectionException`: no host could be reached, the TLS handshake failed, or the query timed out.
  Answer 503.
* `ClickHouseException`: ClickHouse refused or failed the query. `errorCode` and `errorName` are ClickHouse's
  (60 `UNKNOWN_TABLE`, 62 `SYNTAX_ERROR`, 159 `TIMEOUT_EXCEEDED`, 241 `MEMORY_LIMIT_EXCEEDED`,
  516 `AUTHENTICATION_FAILED`, ...), with `httpStatus` and `queryId`.

Queries running when ClickHouse shuts down fail with `QUERY_WAS_CANCELLED`, the same code a `cancel()` gives.

### Diagnostics

```php
$clickhouse->health();          // whether ClickHouse answers with this connection's credentials; never throws
$clickhouse->serverVersion();   // '25.8.33.6'
```

## Failover and FrankenPHP worker mode

One client per connection is kept for the life of each worker. It holds nothing between requests except the list
of hosts that recently failed.

When a host refuses the connection or its name does not resolve, the statement never reached it, so any statement
moves on to the next host. Reads also move on after other connection failures. Timeouts are never retried,
because the query may still be running. A host that failed is skipped for `host_retry_after` seconds, and the
warning goes to the Logger driver. When every host has failed recently, they are all tried again.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run against ClickHouse in Docker, over HTTP and HTTPS:
tests/Integration/start-services.sh
vendor/bin/phpunit
docker compose -f tests/Integration/compose.yaml down -v
```

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems.

They provide a standardized interface for interacting with different data sources, allowing developers to
focus on building their applications rather than dealing with the complexities of each data source.

Drivers are basically Container Dependencies injected into Kipchak's Service Container.

## Licence

MIT
