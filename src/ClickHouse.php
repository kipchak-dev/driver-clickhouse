<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse;

use Kipchak\Core\Interfaces;
use Kipchak\Driver\ClickHouse\Library\CacheFactory;
use Kipchak\Driver\ClickHouse\Library\ClickHouseClient;
use Kipchak\Driver\ClickHouse\Library\ConnectionConfig;
use Kipchak\Driver\ClickHouse\Library\Transport;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Http\Library\Http as HttpClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ClickHouse connections from config/kipchak.clickhouse.php, each a ClickHouseClient in the container under
 * drivers.database.clickhouse.<name>. Requests go over ClickHouse's HTTP interface through the HTTP driver's
 * client; warnings go to the Logger driver.
 */
class ClickHouse implements Interfaces\Driver
{
    protected static ?ContainerInterface $container = null;

    public static function initialise(ContainerInterface $container, ?string $configFileName = null): void
    {
        self::$container = $container;
        self::set($configFileName);
    }

    public static function set(?string $configFileName = null): void
    {
        $config = Config::get($configFileName ?? 'kipchak.clickhouse');

        if (empty($config['connections'])) {
            return;
        }

        foreach ($config['connections'] as $connectionName => $connectionDetails) {
            self::$container->set(
                'drivers.database.clickhouse.' . $connectionName,
                function (ContainerInterface $c) use ($connectionName, $connectionDetails): ClickHouseClient {
                    return self::createClient((string) $connectionName, (array) $connectionDetails);
                }
            );
        }
    }

    public static function get(?string $configKey = null): ClickHouseClient
    {
        return self::$container->get('drivers.database.clickhouse.' . ($configKey ?? 'default'));
    }

    protected static function createClient(string $connectionName, array $connectionDetails): ClickHouseClient
    {
        $config = ConnectionConfig::fromArray($connectionName, $connectionDetails);

        return new ClickHouseClient(
            new Transport(new HttpClient(), $config, self::logger()),
            $config,
            (new CacheFactory(self::$container))->create($config->cache, $connectionName),
        );
    }

    private static function logger(): LoggerInterface
    {
        if (self::$container->has('drivers.logger')) {
            $logger = self::$container->get('drivers.logger');
            if ($logger instanceof LoggerInterface) {
                return $logger;
            }
        }

        return new NullLogger();
    }
}
