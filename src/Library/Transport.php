<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Library;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseConnectionException;
use Kipchak\Driver\ClickHouse\Exception\ClickHouseException;
use Psr\Log\LoggerInterface;

/**
 * Sends statements to ClickHouse's HTTP interface through the HTTP driver's client, failing over across the
 * connection's hosts.
 *
 * A host that cannot be reached is skipped for host_retry_after seconds. Any statement moves on to the next host
 * when the connection was refused or the name did not resolve, because the statement never reached the server.
 * Read-only statements also move on after other connection failures. Timeouts are never retried, because the
 * query may still be running.
 *
 * Holds no per-request state; the hosts that are down are remembered for the life of the worker.
 */
final class Transport
{
    /** cURL errors where the request never reached the server: could not resolve, could not connect. */
    private const array NOT_SENT = [6, 7];

    private const int TIMED_OUT = 28;

    /** @var array<string, int> Host => when to try it again. */
    private array $downUntil = [];

    public function __construct(
        private readonly Factory $http,
        private readonly ConnectionConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POSTs a statement (and, for inserts, its data) and returns the response once ClickHouse has accepted it.
     * A streamed response's body is read as it arrives; any other is downloaded first.
     *
     * @param array<string, scalar> $query Settings, parameters and the query ID, sent in the query string.
     * @throws ClickHouseException When ClickHouse answers with an error.
     * @throws ClickHouseConnectionException When no host could be reached.
     */
    public function post(string $body, array $query, string $queryId, bool $readOnly, bool $stream = false): Response
    {
        $gzip = $this->config->compression && !$readOnly && strlen($body) > 1024;
        if ($gzip) {
            $body = (string) gzencode($body, 1);
        }
        // ClickHouse holds back a gzip response until its buffer fills, which would stop a stream from streaming.
        $compressResponse = $this->config->compression && !$stream;
        if ($compressResponse) {
            $query['enable_http_compression'] = 1;
        }

        $hosts = $this->hostsInOrder();
        $failure = null;
        foreach ($hosts as $index => $host) {
            $request = $this->pending($stream, $compressResponse)->withBody($body, 'text/plain; charset=UTF-8');
            if ($gzip) {
                $request = $request->withHeaders(['Content-Encoding' => 'gzip']);
            }

            try {
                $response = $request->send('POST', $host . '/?' . http_build_query($query));
            } catch (ConnectionException $exception) {
                $failure = $exception;
                if (!self::canTryNextHost($exception, $readOnly)) {
                    break;
                }
                $this->downUntil[$host] = time() + $this->config->hostRetryAfter;
                if ($index < count($hosts) - 1) {
                    $this->logger->warning('ClickHouse host ' . $host . ' for connection "' . $this->config->name . '" is unreachable; trying the next host.', [
                        'host' => $host,
                        'connection' => $this->config->name,
                        'error' => $exception->getMessage(),
                    ]);
                }
                continue;
            }

            unset($this->downUntil[$host]);
            if ($response->status() >= 400) {
                throw ClickHouseException::fromResponse(
                    $response->body(),
                    $response->status(),
                    $response->header('X-ClickHouse-Query-Id') ?: $queryId,
                    $response->header('X-ClickHouse-Exception-Code') ?: null,
                );
            }

            return $response;
        }

        throw new ClickHouseConnectionException(
            'ClickHouse connection "' . $this->config->name . '" failed: ' . $failure?->getMessage(),
            queryId: $queryId,
            previous: $failure,
        );
    }

    /**
     * The hosts to try: those not known to be down in their configured order, then the rest, so a request is still
     * attempted when every host has failed recently.
     *
     * @return list<string>
     */
    private function hostsInOrder(): array
    {
        $now = time();
        $up = [];
        $down = [];
        foreach ($this->config->hosts as $host) {
            if (($this->downUntil[$host] ?? 0) > $now) {
                $down[] = $host;
            } else {
                $up[] = $host;
            }
        }

        return [...$up, ...$down];
    }

    private static function canTryNextHost(ConnectionException $exception, bool $readOnly): bool
    {
        $previous = $exception->getPrevious();
        $errno = $previous instanceof ConnectException ? ($previous->getHandlerContext()['errno'] ?? null) : null;

        if ($errno === self::TIMED_OUT || ($errno === null && stripos($exception->getMessage(), 'timed out') !== false)) {
            return false;
        }

        return in_array($errno, self::NOT_SENT, true) || $readOnly;
    }

    private function pending(bool $stream, bool $compressResponse): PendingRequest
    {
        $headers = [
            'X-ClickHouse-User' => $this->config->username,
            'X-ClickHouse-Database' => $this->config->database,
        ];
        if ($this->config->password !== '') {
            $headers['X-ClickHouse-Key'] = $this->config->password;
        }
        if ($compressResponse) {
            $headers['Accept-Encoding'] = 'gzip';
        }

        // A stream may run for as long as rows keep arriving; only a silence longer than the timeout ends it.
        $timeouts = $stream
            ? ['stream' => true, 'timeout' => $this->config->timeout, 'read_timeout' => $this->config->timeout]
            : ['timeout' => $this->config->timeout];

        return $this->http
            ->withHeaders($headers)
            ->withOptions([
                'connect_timeout' => $this->config->connectTimeout,
                'decode_content' => true,
                ...$timeouts,
                ...$this->config->tls,
            ]);
    }
}
