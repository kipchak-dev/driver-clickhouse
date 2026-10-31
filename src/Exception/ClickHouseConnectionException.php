<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Exception;

/**
 * ClickHouse could not be reached on any host, the TLS handshake failed, or the request timed out.
 */
final class ClickHouseConnectionException extends ClickHouseException
{
}
