<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * Exports of the company's data.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type ExportsController_exportSaftPtQuery from Types
 * @phpstan-import-type ExportsController_exportSaftPtResponse from Types
 */
final class Exports extends Resource
{
    /**
     * Generates the company's SAF-T PT for a month or a period and returns a
     * temporary download link.
     *
     * @param ExportsController_exportSaftPtQuery $query
     * @param RequestOptions $options
     * @return ExportsController_exportSaftPtResponse
     */
    public function saftPt(array $query = [], array $options = []): array
    {
        return $this->client->call('ExportsController_exportSaftPt', ['query' => $query], $options);
    }
}
