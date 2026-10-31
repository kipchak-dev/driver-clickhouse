<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Library;

use InvalidArgumentException;

/**
 * One connection's settings from kipchak.clickhouse, validated when the client is first used.
 */
final readonly class ConnectionConfig
{
    /**
     * @param list<string> $hosts Base URLs, tried in order.
     * @param array<string, scalar> $settings ClickHouse settings sent with every query.
     * @param array<string, mixed> $tls Guzzle request options for TLS (verify, cert, ssl_key).
     */
    public function __construct(
        public string $name,
        public array $hosts,
        public string $database,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public float $timeout,
        public float $connectTimeout,
        public int $hostRetryAfter,
        public bool $compression,
        public int $insertBatchSize,
        public array $settings,
        public array $tls,
        public ?string $cache,
    ) {
    }

    public static function fromArray(string $name, array $config): self
    {
        $hosts = $config['hosts'] ?? null;
        $hosts = is_string($hosts) ? [$hosts] : $hosts;
        if (!is_array($hosts) || $hosts === []) {
            throw self::invalid($name, 'hosts is required: one or more URLs such as "http://clickhouse:8123"');
        }
        $hosts = array_map(static function (mixed $host) use ($name): string {
            if (!is_string($host) || !preg_match('~^https?://[^\s/?#]+/?$~', $host)) {
                throw self::invalid($name, 'each host must be an http:// or https:// URL with no path, such as "https://clickhouse:8443"');
            }

            return rtrim($host, '/');
        }, array_values($hosts));

        $settings = $config['settings'] ?? [];
        if (!is_array($settings)) {
            throw self::invalid($name, 'settings must be an array of ClickHouse settings');
        }
        foreach ($settings as $setting => $value) {
            if (!is_string($setting) || !is_scalar($value)) {
                throw self::invalid($name, 'settings must map setting names to scalar values');
            }
        }

        $batchSize = (int) ($config['insert_batch_size'] ?? 10000);
        if ($batchSize < 1) {
            throw self::invalid($name, 'insert_batch_size must be at least 1');
        }

        $cache = $config['cache'] ?? null;
        if ($cache !== null && (!is_string($cache) || $cache === '')) {
            throw self::invalid($name, 'cache must be "memory", "file" or a container key');
        }

        return new self(
            name: $name,
            hosts: $hosts,
            database: (string) ($config['database'] ?? 'default'),
            username: (string) ($config['username'] ?? 'default'),
            password: (string) ($config['password'] ?? ''),
            timeout: (float) ($config['timeout'] ?? 30.0),
            connectTimeout: (float) ($config['connect_timeout'] ?? 2.0),
            hostRetryAfter: (int) ($config['host_retry_after'] ?? 30),
            compression: (bool) ($config['compression'] ?? true),
            insertBatchSize: $batchSize,
            settings: $settings,
            tls: self::tlsOptions($name, $config['tls'] ?? []),
            cache: $cache,
        );
    }

    /**
     * Maps the tls block to Guzzle request options.
     *
     * @return array<string, mixed>
     */
    private static function tlsOptions(string $name, mixed $tls): array
    {
        if (!is_array($tls)) {
            throw self::invalid($name, 'tls must be an array');
        }

        $options = [];
        if (isset($tls['ca']) && $tls['ca'] !== '') {
            $options['verify'] = (string) $tls['ca'];
        } elseif (array_key_exists('verify', $tls)) {
            $options['verify'] = (bool) $tls['verify'];
        }

        $hasCert = isset($tls['cert']) && $tls['cert'] !== '';
        $hasKey = isset($tls['key']) && $tls['key'] !== '';
        if ($hasCert !== $hasKey) {
            throw self::invalid($name, 'client certificates need both tls.cert and tls.key');
        }
        if ($hasCert) {
            $options['cert'] = (string) $tls['cert'];
            $options['ssl_key'] = (string) $tls['key'];
        }

        return $options;
    }

    private static function invalid(string $name, string $problem): InvalidArgumentException
    {
        return new InvalidArgumentException('ClickHouse connection "' . $name . '": ' . $problem . '.');
    }
}
