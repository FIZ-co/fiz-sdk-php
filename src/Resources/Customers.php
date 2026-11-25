<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Customers.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type CreateCustomerResponseDto from Types
 * @phpstan-import-type CustomersController_createBody from Types
 * @phpstan-import-type CustomersController_createResponse from Types
 * @phpstan-import-type CustomersController_deleteResponse from Types
 * @phpstan-import-type CustomersController_findAllListAllQuery from Types
 * @phpstan-import-type CustomersController_findAllQuery from Types
 * @phpstan-import-type CustomersController_findAllResponse from Types
 * @phpstan-import-type CustomersController_findOneResponse from Types
 * @phpstan-import-type CustomersController_restoreResponse from Types
 * @phpstan-import-type CustomersController_updateBody from Types
 * @phpstan-import-type CustomersController_updateResponse from Types
 */
final class Customers extends Resource
{
    /**
     * @param CustomersController_createBody $body
     * @param RequestOptions $options
     * @return CustomersController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('CustomersController_create', ['body' => $body], $options);
    }

    /**
     * @param CustomersController_findAllQuery $query
     * @param RequestOptions $options
     * @return CustomersController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('CustomersController_findAll', ['query' => $query], $options);
    }

    /**
     * Every customer matching the filters, fetching pages as you iterate.
     *
     * @param CustomersController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, CreateCustomerResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, CreateCustomerResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('CustomersController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return CustomersController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('CustomersController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param CustomersController_updateBody $body
     * @param RequestOptions $options
     * @return CustomersController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('CustomersController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Archives the customer; `restore` brings it back.
     *
     * @param RequestOptions $options
     * @return CustomersController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('CustomersController_delete', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param RequestOptions $options
     * @return CustomersController_restoreResponse
     */
    public function restore(string $id, array $options = []): array
    {
        return $this->client->call('CustomersController_restore', ['path' => ['id' => $id]], $options);
    }
}
