<?php

declare(strict_types=1);

namespace Fiz\Exception;

/**
 * No complete response arrived (network failure, timeout, or a success whose
 * body was cut off or is not JSON). A write that carried an Idempotency-Key
 * is safe to repeat with the SAME key, which the SDK does automatically
 * before throwing this.
 */
class FizConnectionException extends FizException
{
    public function __construct(
        string $message,
        /** The Idempotency-Key the request carried, if it was a write. */
        public readonly ?string $idempotencyKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
