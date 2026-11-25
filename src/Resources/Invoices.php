<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Invoices, simplified invoices, invoice-receipts and the receipts and credit
 * notes raised from them. A document is created as a draft (no fiscal
 * effect) and becomes a fiscal document only when issued.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type InvoiceListItemResponseDto from Types
 * @phpstan-import-type InvoicesController_cancelBody from Types
 * @phpstan-import-type InvoicesController_cancelResponse from Types
 * @phpstan-import-type InvoicesController_createBody from Types
 * @phpstan-import-type InvoicesController_createResponse from Types
 * @phpstan-import-type InvoicesController_deleteResponse from Types
 * @phpstan-import-type InvoicesController_findAllListAllQuery from Types
 * @phpstan-import-type InvoicesController_findAllQuery from Types
 * @phpstan-import-type InvoicesController_findAllResponse from Types
 * @phpstan-import-type InvoicesController_findOneResponse from Types
 * @phpstan-import-type InvoicesController_getPdfQuery from Types
 * @phpstan-import-type InvoicesController_getPdfResponse from Types
 * @phpstan-import-type InvoicesController_issueBody from Types
 * @phpstan-import-type InvoicesController_issueResponse from Types
 * @phpstan-import-type InvoicesController_payBody from Types
 * @phpstan-import-type InvoicesController_payResponse from Types
 * @phpstan-import-type InvoicesController_updateBody from Types
 * @phpstan-import-type InvoicesController_updateResponse from Types
 */
final class Invoices extends Resource
{
    /**
     * Creates a draft. Not reported to the AT until `issue`.
     *
     * @param InvoicesController_createBody $body
     * @param RequestOptions $options
     * @return InvoicesController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('InvoicesController_create', ['body' => $body], $options);
    }

    /**
     * One page of documents, newest first by default.
     *
     * @param InvoicesController_findAllQuery $query
     * @param RequestOptions $options
     * @return InvoicesController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('InvoicesController_findAll', ['query' => $query], $options);
    }

    /**
     * Every document matching the filters, fetching pages as you iterate.
     *
     * @param InvoicesController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, InvoiceListItemResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, InvoiceListItemResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('InvoicesController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return InvoicesController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('InvoicesController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * Changes a draft. Issued documents cannot be changed.
     *
     * @param InvoicesController_updateBody $body
     * @param RequestOptions $options
     * @return InvoicesController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('InvoicesController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Deletes a draft.
     *
     * @param RequestOptions $options
     * @return InvoicesController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('InvoicesController_delete', ['path' => ['id' => $id]], $options);
    }

    /**
     * Issues a draft: numbers it in its series, adds the ATCUD and QR code and
     * reports it to the AT. **Fiscal and irreversible** (with a production key).
     * Pass the draft's `updatedAt` (from `create`, `update` or `retrieve`) as
     * `expectedUpdatedAt`; if it is missing, do not issue. A draft changed
     * since you read it is refused instead of issued.
     *
     * @param InvoicesController_issueBody $body
     * @param RequestOptions $options
     * @return InvoicesController_issueResponse
     */
    public function issue(string $id, array $body = [], array $options = []): array
    {
        return $this->client->call('InvoicesController_issue', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Records a payment and issues its receipt. **Fiscal and irreversible.**
     *
     * @param InvoicesController_payBody $body
     * @param RequestOptions $options
     * @return InvoicesController_payResponse
     */
    public function pay(string $id, array $body, array $options = []): array
    {
        return $this->client->call('InvoicesController_pay', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Cancels an issued document and reports it to the AT. **Fiscal and irreversible.**
     *
     * @param InvoicesController_cancelBody $body
     * @param RequestOptions $options
     * @return InvoicesController_cancelResponse
     */
    public function cancel(string $id, array $body, array $options = []): array
    {
        return $this->client->call('InvoicesController_cancel', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Link to the document's PDF. Use `downloadPdf` for the bytes.
     *
     * @param InvoicesController_getPdfQuery $query
     * @param RequestOptions $options
     * @return InvoicesController_getPdfResponse
     */
    public function pdf(string $id, array $query = [], array $options = []): array
    {
        return $this->client->call('InvoicesController_getPdf', ['path' => ['id' => $id], 'query' => $query], $options);
    }

    /**
     * The document's PDF file, as a string of bytes.
     *
     * @param InvoicesController_getPdfQuery $query
     * @param RequestOptions $options
     */
    public function downloadPdf(string $id, array $query = [], array $options = []): string
    {
        $file = $this->pdf($id, $query, $options);
        return $this->client->download(
            self::requireUrl($file['url'], $id),
            isset($options['timeout']) ? ['timeout' => $options['timeout']] : [],
        );
    }
}
