<?php

declare(strict_types=1);

namespace Fiz\Exception;

/** 403: the credential cannot do this (scope, plan or permission). */
class FizPermissionException extends FizApiException
{
}
