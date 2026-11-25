<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * VAT rules.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type VatController_calculateBody from Types
 * @phpstan-import-type VatController_calculateResponse from Types
 * @phpstan-import-type VatController_validateClientBody from Types
 * @phpstan-import-type VatController_validateClientResponse from Types
 */
final class Vat extends Resource
{
    /**
     * The VAT rate and exemption that apply to an item for a customer.
     *
     * @param VatController_calculateBody $body
     * @param RequestOptions $options
     * @return VatController_calculateResponse
     */
    public function calculate(array $body, array $options = []): array
    {
        return $this->client->call('VatController_calculate', ['body' => $body], $options);
    }

    /**
     * @param VatController_validateClientBody $body
     * @param RequestOptions $options
     * @return VatController_validateClientResponse
     */
    public function validateClient(array $body, array $options = []): array
    {
        return $this->client->call('VatController_validateClient', ['body' => $body], $options);
    }
}
