<?php

declare(strict_types=1);

namespace Fiz\Exception;

/** 422: the Idempotency-Key was already used with a different request. */
class FizUnprocessableEntityException extends FizApiException
{
}
