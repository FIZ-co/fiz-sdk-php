<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * The connected company.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type CompanyController_getCompanyResponse from Types
 */
final class Company extends Resource
{
    /**
     * The company and its issuance readiness (`readiness`): check it before
     * issuing. `environment` says whether the key reaches production or the
     * sandbox.
     *
     * @param RequestOptions $options
     * @return CompanyController_getCompanyResponse
     */
    public function retrieve(array $options = []): array
    {
        return $this->client->call('CompanyController_getCompany', [], $options);
    }
}
