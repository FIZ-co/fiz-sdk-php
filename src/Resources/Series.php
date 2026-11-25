<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * Invoice series and their AT registration.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type SeriesController_getAllResponse from Types
 */
final class Series extends Resource
{
    /**
     * @param RequestOptions $options
     * @return SeriesController_getAllResponse
     */
    public function list(array $options = []): array
    {
        return $this->client->call('SeriesController_getAll', [], $options);
    }
}
