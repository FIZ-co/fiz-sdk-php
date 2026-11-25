<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Bank connections and transactions (open banking). Production only.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type BankController_getConnectionsResponse from Types
 * @phpstan-import-type BankController_getTransactionsListAllQuery from Types
 * @phpstan-import-type BankController_getTransactionsQuery from Types
 * @phpstan-import-type BankController_getTransactionsResponse from Types
 * @phpstan-import-type BankTransactionResponseDto from Types
 */
final class Bank extends Resource
{
    /**
     * @param RequestOptions $options
     * @return BankController_getConnectionsResponse
     */
    public function connections(array $options = []): array
    {
        return $this->client->call('BankController_getConnections', [], $options);
    }

    /**
     * @param BankController_getTransactionsQuery $query
     * @param RequestOptions $options
     * @return BankController_getTransactionsResponse
     */
    public function transactions(array $query, array $options = []): array
    {
        return $this->client->call('BankController_getTransactions', ['query' => $query], $options);
    }

    /**
     * Every transaction matching the filters, fetching pages as you iterate.
     *
     * @param BankController_getTransactionsListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, BankTransactionResponseDto, mixed, void>
     */
    public function transactionsAll(array $query, array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, BankTransactionResponseDto, mixed, void> */
        return Pagination::byPage(
            fn (array $page): array => $this->client->call('BankController_getTransactions', ['query' => $page], $options),
            $query,
        );
    }
}
