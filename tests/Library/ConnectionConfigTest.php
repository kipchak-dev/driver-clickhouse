<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Tests\Library;

use InvalidArgumentException;
use Kipchak\Driver\ClickHouse\Library\ConnectionConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = ConnectionConfig::fromArray('default', ['hosts' => 'http://clickhouse:8123/']);

        $this->assertSame(['http://clickhouse:8123'], $config->hosts);
        $this->assertSame('default', $config->database);
        $this->assertSame('default', $config->username);
        $this->assertSame('', $config->password);
        $this->assertSame(30.0, $config->timeout);
        $this->assertSame(2.0, $config->connectTimeout);
        $this->assertSame(30, $config->hostRetryAfter);
        $this->assertTrue($config->compression);
        $this->assertSame(10000, $config->insertBatchSize);
        $this->assertSame([], $config->settings);
        $this->assertSame([], $config->tls);
        $this->assertNull($config->cache);
    }

    public function testFullConfig(): void
    {
        $config = ConnectionConfig::fromArray('analytics', [
            'hosts' => ['https://ch1:8443', 'https://ch2:8443'],
            'database' => 'analytics',
            'username' => 'api',
            'password' => 'secret',
            'timeout' => 5,
            'compression' => false,
            'insert_batch_size' => 500,
            'settings' => ['max_execution_time' => 4, 'session_timezone' => 'UTC'],
            'tls' => ['ca' => '/ca.pem', 'cert' => '/c.pem', 'key' => '/k.pem'],
            'cache' => 'drivers.cache.valkey.cache',
        ]);

        $this->assertSame(['https://ch1:8443', 'https://ch2:8443'], $config->hosts);
        $this->assertSame(5.0, $config->timeout);
        $this->assertFalse($config->compression);
        $this->assertSame(['verify' => '/ca.pem', 'cert' => '/c.pem', 'ssl_key' => '/k.pem'], $config->tls);
        $this->assertSame('drivers.cache.valkey.cache', $config->cache);
    }

    public function testVerificationCanBeTurnedOff(): void
    {
        $this->assertSame(['verify' => false], ConnectionConfig::fromArray('d', ['hosts' => 'https://a', 'tls' => ['verify' => false]])->tls);
    }

    public static function invalid(): iterable
    {
        yield 'no hosts' => [[], 'hosts is required'];
        yield 'empty hosts' => [['hosts' => []], 'hosts is required'];
        yield 'not a URL' => [['hosts' => 'clickhouse:8123'], 'http:// or https://'];
        yield 'native protocol' => [['hosts' => 'tcp://clickhouse:9000'], 'http:// or https://'];
        yield 'path' => [['hosts' => 'http://clickhouse:8123/db'], 'no path'];
        yield 'query' => [['hosts' => 'http://clickhouse:8123?user=x'], 'no path'];
        yield 'settings not an array' => [['hosts' => 'http://a', 'settings' => 'x'], 'settings must be an array'];
        yield 'settings not scalar' => [['hosts' => 'http://a', 'settings' => ['a' => []]], 'scalar values'];
        yield 'batch size' => [['hosts' => 'http://a', 'insert_batch_size' => 0], 'insert_batch_size'];
        yield 'cert without key' => [['hosts' => 'https://a', 'tls' => ['cert' => '/c.pem']], 'both tls.cert and tls.key'];
        yield 'empty cache' => [['hosts' => 'http://a', 'cache' => ''], 'cache must be'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidConfig(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ConnectionConfig::fromArray('default', $config);
    }

    public function testErrorsNameTheConnection(): void
    {
        $this->expectExceptionMessage('ClickHouse connection "analytics": hosts is required');
        ConnectionConfig::fromArray('analytics', []);
    }
}
