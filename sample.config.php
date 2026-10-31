<?php

use function Kipchak\Core\env;

return [
    'connections' => [
        'default' => [
            // One or more ClickHouse HTTP(S) endpoints (8123 for HTTP, 8443 for HTTPS), tried in order.
            'hosts' => [env('CLICKHOUSE_URL', 'http://clickhouse:8123')],
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USER', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),

            // Seconds. 'timeout' covers a whole query, or for each() the wait for each part of the result.
            'timeout' => 30,
            'connect_timeout' => 2,

            // Seconds to skip a host that could not be reached before trying it again.
            'host_retry_after' => 30,

            // gzip for results and large inserts.
            'compression' => true,

            // Rows per INSERT when insert() is given more.
            'insert_batch_size' => 10000,

            // ClickHouse settings sent with every query (https://clickhouse.com/docs/operations/settings/settings).
            'settings' => [
                // 'max_execution_time' => 25,
                // 'session_timezone' => 'UTC',
            ],

            // Cache for select() results asked for with a cacheTtl: 'memory', 'file' (the Filecache driver) or a
            // container key such as 'drivers.cache.valkey.cache'. Leave null for none.
            'cache' => null,

            // TLS, for https:// hosts. 'ca' verifies ClickHouse's certificate; 'cert' and 'key' send a client
            // certificate.
            // 'tls' => [
            //     'ca' => '/etc/ssl/clickhouse/ca.pem',
            //     'cert' => '/etc/ssl/clickhouse/client.pem',
            //     'key' => '/etc/ssl/clickhouse/client-key.pem',
            // ],
        ],
    ],
];
