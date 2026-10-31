<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests;

use DI\Container;
use InvalidArgumentException;
use Kipchak\Driver\ClickHouse\ClickHouse;
use Kipchak\Driver\ClickHouse\Library\CacheFactory;
use Kipchak\Driver\ClickHouse\Library\ClickHouseClient;
use Kipchak\Driver\Config\Config;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class ClickHouseDriverTest extends TestCase
{
    private const array CONNECTION = ['hosts' => 'http://clickhouse:8123'];

    private function primeDriver(array $config): Container
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.clickhouse' => $config]);
        ClickHouse::initialise($container);

        return $container;
    }

    public function testRegistersOneContainerEntryPerConnection(): void
    {
        $container = $this->primeDriver(['connections' => [
            'default' => self::CONNECTION,
            'archive' => ['hosts' => 'https://archive:8443', 'database' => 'archive'],
        ]]);

        $this->assertTrue($container->has('drivers.database.clickhouse.default'));
        $this->assertTrue($container->has('drivers.database.clickhouse.archive'));
        $this->assertInstanceOf(ClickHouseClient::class, ClickHouse::get());
        $this->assertInstanceOf(ClickHouseClient::class, ClickHouse::get('archive'));
        $this->assertSame(ClickHouse::get(), ClickHouse::get('default'), 'One client per connection per worker');
    }

    public function testCreatingAClientDoesNotConnect(): void
    {
        $this->primeDriver(['connections' => ['default' => ['hosts' => 'http://unreachable.invalid:8123']]]);

        $this->assertInstanceOf(ClickHouseClient::class, ClickHouse::get());
    }

    public function testNoConnectionsRegistersNothing(): void
    {
        $container = $this->primeDriver([]);

        $this->assertFalse($container->has('drivers.database.clickhouse.default'));
    }

    public function testCustomConfigFileName(): void
    {
        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['analytics' => ['connections' => ['default' => self::CONNECTION]]]);
        ClickHouse::initialise($container, 'analytics');

        $this->assertInstanceOf(ClickHouseClient::class, ClickHouse::get());
    }

    public function testInvalidConnectionFailsWhenUsed(): void
    {
        $this->primeDriver(['connections' => ['default' => ['hosts' => 'clickhouse:9000']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http:// or https://');
        ClickHouse::get();
    }

    public function testLoggerFromTheLoggerDriverIsUsed(): void
    {
        $container = $this->primeDriver(['connections' => ['default' => self::CONNECTION]]);
        $logger = new NullLogger();
        $container->set('drivers.logger', $logger);

        $transport = $this->property(ClickHouse::get(), 'transport');
        $this->assertSame($logger, $this->property($transport, 'logger'));
    }

    public function testConnectionCacheIsBuilt(): void
    {
        $container = $this->primeDriver(['connections' => ['default' => ['cache' => 'drivers.cache.valkey.cache'] + self::CONNECTION]]);
        $container->set('drivers.cache.valkey.cache', new ArrayAdapter());

        $this->assertInstanceOf(CacheItemPoolInterface::class, $this->property(ClickHouse::get(), 'cache'));
    }

    public function testCacheSettings(): void
    {
        $container = new Container();
        $factory = new CacheFactory($container);

        $this->assertNull($factory->create(null, 'default'));
        $this->assertInstanceOf(ArrayAdapter::class, $factory->create('memory', 'default'));

        $container->set('drivers.cache.file', new ArrayAdapter());
        $container->set('drivers.cache.valkey.cache', new ArrayAdapter());
        $container->set('psr16', new Psr16Cache(new ArrayAdapter()));
        $this->assertInstanceOf(CacheItemPoolInterface::class, $factory->create('file', 'default'));
        $this->assertInstanceOf(CacheItemPoolInterface::class, $factory->create('drivers.cache.valkey.cache', 'default'));
        $this->assertInstanceOf(CacheItemPoolInterface::class, $factory->create('psr16', 'default'));
    }

    public function testSharedCachesAreNamespacedPerConnection(): void
    {
        $container = new Container();
        $shared = new ArrayAdapter();
        $container->set('drivers.cache.valkey.cache', $shared);
        $factory = new CacheFactory($container);

        $a = $factory->create('drivers.cache.valkey.cache', 'a');
        $b = $factory->create('drivers.cache.valkey.cache', 'b');
        $a->save($a->getItem('k')->set('from a'));

        $this->assertFalse($b->getItem('k')->isHit());
        $b->clear();
        $this->assertTrue($a->getItem('k')->isHit(), 'Clearing one connection\'s cache leaves the others');
    }

    public function testFileCacheNeedsTheFilecacheDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Initialise Kipchak\Driver\Filecache\Filecache before ClickHouse');
        (new CacheFactory(new Container()))->create('file', 'default');
    }

    public function testUnknownCacheIsExplained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not "memory", "file" or a container entry');
        (new CacheFactory(new Container()))->create('drivers.cache.nope', 'default');
    }

    public function testNonCacheContainerEntryIsRejected(): void
    {
        $container = new Container();
        $container->set('not-a-cache', new \stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a PSR-6 or PSR-16 cache');
        (new CacheFactory($container))->create('not-a-cache', 'default');
    }

    public function testSampleConfigIsValid(): void
    {
        require_once dirname((string) (new \ReflectionClass(\Kipchak\Core\Api::class))->getFileName()) . '/Functions.php';
        $this->primeDriver(require __DIR__ . '/../sample.config.php');
        $config = $this->property(ClickHouse::get(), 'config');

        $this->assertSame(['http://clickhouse:8123'], $config->hosts);
        $this->assertTrue($config->compression);
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
