<?php

declare(strict_types=1);

namespace Fiz\Exception;

/** 401: the API key or access token is missing, invalid or revoked. */
class FizAuthenticationException extends FizApiException
{
}
