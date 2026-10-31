<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Library;

use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Builds the cache for query results from a connection's 'cache' setting:
 *
 *  - null (the default): no cache; select() with a cacheTtl throws.
 *  - "memory": in the worker's memory, so each FrankenPHP worker keeps its own copy. Outside worker mode it only
 *    lasts one request.
 *  - "file": Kipchak's Filecache driver, shared by every PHP process on the machine.
 *  - anything else: a container entry holding a PSR-6 or PSR-16 cache, such as "drivers.cache.valkey.cache" from
 *    the Valkey driver, shared by every machine using it.
 *
 * Each connection gets its own namespace. Only select() results asked for with a cacheTtl are cached.
 */
final class CacheFactory
{
    private const int MEMORY_ITEMS = 10000;

    /** The container key of Kipchak's Filecache driver. */
    private const string FILECACHE = 'drivers.cache.file';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function create(?string $setting, string $connectionName): ?CacheItemPoolInterface
    {
        if ($setting === null) {
            return null;
        }
        $namespace = 'kipchak-clickhouse-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $connectionName);

        return match ($setting) {
            'memory' => new ArrayAdapter(maxItems: self::MEMORY_ITEMS),
            'file' => $this->shared(self::FILECACHE, $namespace, $connectionName),
            default => $this->shared($setting, $namespace, $connectionName),
        };
    }

    private function shared(string $service, string $namespace, string $connectionName): CacheItemPoolInterface
    {
        if ($service === self::FILECACHE && !$this->container->has($service)) {
            throw new InvalidArgumentException(
                'ClickHouse connection "' . $connectionName . '": cache "file" uses the Filecache driver. '
                . 'Initialise Kipchak\\Driver\\Filecache\\Filecache before ClickHouse in drivers/drivers.php.'
            );
        }
        if (!$this->container->has($service)) {
            throw new InvalidArgumentException(
                'ClickHouse connection "' . $connectionName . '": cache "' . $service . '" is not "memory", "file" '
                . 'or a container entry. Is the driver that provides it initialised before ClickHouse?'
            );
        }

        $cache = $this->container->get($service);

        return match (true) {
            $cache instanceof NamespacedPoolInterface && $cache instanceof CacheItemPoolInterface => $cache->withSubNamespace($namespace),
            $cache instanceof CacheItemPoolInterface => new ProxyAdapter($cache, $namespace),
            $cache instanceof CacheInterface => new Psr16Adapter($cache, $namespace),
            default => throw new InvalidArgumentException(
                'ClickHouse connection "' . $connectionName . '": cache "' . $service . '" is a '
                . get_debug_type($cache) . ', not a PSR-6 or PSR-16 cache.'
            ),
        };
    }
}
