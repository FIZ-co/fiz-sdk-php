<?php

declare(strict_types=1);

namespace Fiz\Exception;

/**
 * 409: a conflict. With an Idempotency-Key it means the first attempt is
 * still running (the SDK already waited and retried) or that its outcome is
 * unknown: check whether it took effect, then retry with a NEW key.
 */
class FizConflictException extends FizApiException
{
}
