<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\Exception\FizException;
use Fiz\FizClient;

abstract class Resource
{
    public function __construct(protected readonly FizClient $client)
    {
    }

    /** The link a `pdf` call returned, or an exception when the API sent none. */
    protected static function requireUrl(?string $url, string $id): string
    {
        if ($url === null || $url === '') {
            throw new FizException("The API returned no PDF link for $id");
        }
        return $url;
    }
}
