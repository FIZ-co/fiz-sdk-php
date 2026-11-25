<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Recurring invoices: a template FIZ issues — and reports to the AT — on its
 * own schedule. Every document it issues carries its id as
 * `scheduleTemplateId` (`$fiz->invoices->list(['scheduleTemplateId' => $id])`).
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type ScheduledInvoiceResponseDto from Types
 * @phpstan-import-type ScheduledInvoicesController_createBody from Types
 * @phpstan-import-type ScheduledInvoicesController_createResponse from Types
 * @phpstan-import-type ScheduledInvoicesController_deleteResponse from Types
 * @phpstan-import-type ScheduledInvoicesController_findAllListAllQuery from Types
 * @phpstan-import-type ScheduledInvoicesController_findAllQuery from Types
 * @phpstan-import-type ScheduledInvoicesController_findAllResponse from Types
 * @phpstan-import-type ScheduledInvoicesController_findOneResponse from Types
 * @phpstan-import-type ScheduledInvoicesController_updateBody from Types
 * @phpstan-import-type ScheduledInvoicesController_updateResponse from Types
 */
final class RecurringInvoices extends Resource
{
    /**
     * @param ScheduledInvoicesController_createBody $body
     * @param RequestOptions $options
     * @return ScheduledInvoicesController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('ScheduledInvoicesController_create', ['body' => $body], $options);
    }

    /**
     * @param ScheduledInvoicesController_findAllQuery $query
     * @param RequestOptions $options
     * @return ScheduledInvoicesController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('ScheduledInvoicesController_findAll', ['query' => $query], $options);
    }

    /**
     * Every recurring invoice, fetching pages as you iterate.
     *
     * @param ScheduledInvoicesController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, ScheduledInvoiceResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, ScheduledInvoiceResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('ScheduledInvoicesController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return ScheduledInvoicesController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('ScheduledInvoicesController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * Changes the schedule, or pauses and resumes it (`status`).
     *
     * @param ScheduledInvoicesController_updateBody $body
     * @param RequestOptions $options
     * @return ScheduledInvoicesController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('ScheduledInvoicesController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Ends the schedule. Documents already issued are kept.
     *
     * @param RequestOptions $options
     * @return ScheduledInvoicesController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('ScheduledInvoicesController_delete', ['path' => ['id' => $id]], $options);
    }
}
