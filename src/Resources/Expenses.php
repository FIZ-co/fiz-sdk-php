<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Purchase documents imported from e-Fatura. Read-only.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type ExpenseResponseDto from Types
 * @phpstan-import-type ExpensesController_findAllListAllQuery from Types
 * @phpstan-import-type ExpensesController_findAllQuery from Types
 * @phpstan-import-type ExpensesController_findAllResponse from Types
 * @phpstan-import-type ExpensesController_statsQuery from Types
 * @phpstan-import-type ExpensesController_statsResponse from Types
 */
final class Expenses extends Resource
{
    /**
     * @param ExpensesController_findAllQuery $query
     * @param RequestOptions $options
     * @return ExpensesController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('ExpensesController_findAll', ['query' => $query], $options);
    }

    /**
     * Every expense matching the filters, fetching pages as you iterate.
     *
     * @param ExpensesController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, ExpenseResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, ExpenseResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('ExpensesController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param ExpensesController_statsQuery $query
     * @param RequestOptions $options
     * @return ExpensesController_statsResponse
     */
    public function stats(array $query = [], array $options = []): array
    {
        return $this->client->call('ExpensesController_stats', ['query' => $query], $options);
    }
}
