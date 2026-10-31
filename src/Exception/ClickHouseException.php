<?php

declare(strict_types=1);

namespace Kipchak\Driver\ClickHouse\Exception;

use RuntimeException;

/**
 * ClickHouse refused or failed a query.
 *
 * $errorCode is ClickHouse's numeric error code and $errorName its name (60 UNKNOWN_TABLE, 62 SYNTAX_ERROR,
 * 159 TIMEOUT_EXCEEDED, 241 MEMORY_LIMIT_EXCEEDED, 516 AUTHENTICATION_FAILED, ...), as listed by
 * SELECT name, code FROM system.errors.
 */
class ClickHouseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?string $errorName = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $queryId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode ?? 0, $previous);
    }

    /**
     * Builds the exception from ClickHouse's error text, "Code: 60. DB::Exception: Unknown table ... (UNKNOWN_TABLE)
     * (version 25.8.1.1 (official build))", either as the body or inside {"exception": "..."}.
     */
    public static function fromResponse(string $body, ?int $httpStatus, ?string $queryId, ?string $codeHeader = null): self
    {
        $text = trim($body);
        $decoded = json_decode($text, true);
        if (is_array($decoded) && isset($decoded['exception']) && is_string($decoded['exception'])) {
            $text = $decoded['exception'];
        }

        $code = $codeHeader !== null && ctype_digit($codeHeader) ? (int) $codeHeader : null;
        if ($code === null && preg_match('/^Code: (\d+)\./', $text, $match)) {
            $code = (int) $match[1];
        }
        $name = preg_match('/\(([A-Z][A-Z0-9_]+)\)(?: \(version [^)]*\)+)?\s*$/', $text, $match) ? $match[1] : null;

        $message = (string) preg_replace('/\s*\(version [^)]*\)+\s*$/', '', $text);
        if ($message === '') {
            $message = 'ClickHouse answered HTTP ' . ($httpStatus ?? '?') . ' with no error message';
        }

        return new self('[ClickHouse] ' . $message, $code, $name, $httpStatus, $queryId);
    }
}
