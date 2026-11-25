<?php

declare(strict_types=1);

namespace Fiz\Exception;

/** 429: too many requests. The SDK already retried, honouring `Retry-After`. */
class FizRateLimitException extends FizApiException
{
}
