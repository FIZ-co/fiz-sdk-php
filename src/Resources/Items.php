<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Catalogue items (products and services) with their VAT.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type CreateItemResponseDto from Types
 * @phpstan-import-type ItemsController_createBody from Types
 * @phpstan-import-type ItemsController_createResponse from Types
 * @phpstan-import-type ItemsController_deleteResponse from Types
 * @phpstan-import-type ItemsController_findAllListAllQuery from Types
 * @phpstan-import-type ItemsController_findAllQuery from Types
 * @phpstan-import-type ItemsController_findAllResponse from Types
 * @phpstan-import-type ItemsController_findOneResponse from Types
 * @phpstan-import-type ItemsController_updateBody from Types
 * @phpstan-import-type ItemsController_updateResponse from Types
 */
final class Items extends Resource
{
    /**
     * @param ItemsController_createBody $body
     * @param RequestOptions $options
     * @return ItemsController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('ItemsController_create', ['body' => $body], $options);
    }

    /**
     * @param ItemsController_findAllQuery $query
     * @param RequestOptions $options
     * @return ItemsController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('ItemsController_findAll', ['query' => $query], $options);
    }

    /**
     * Every item matching the filters, fetching pages as you iterate.
     *
     * @param ItemsController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, CreateItemResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, CreateItemResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('ItemsController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return ItemsController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('ItemsController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param ItemsController_updateBody $body
     * @param RequestOptions $options
     * @return ItemsController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('ItemsController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * @param RequestOptions $options
     * @return ItemsController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('ItemsController_delete', ['path' => ['id' => $id]], $options);
    }
}
