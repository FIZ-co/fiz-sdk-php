<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Transport documents (guias de transporte: GR, GT, GA, GC, GD).
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type TransportDocumentResponseDto from Types
 * @phpstan-import-type TransportDocumentsController_cancelResponse from Types
 * @phpstan-import-type TransportDocumentsController_createBody from Types
 * @phpstan-import-type TransportDocumentsController_createResponse from Types
 * @phpstan-import-type TransportDocumentsController_deleteResponse from Types
 * @phpstan-import-type TransportDocumentsController_findAllListAllQuery from Types
 * @phpstan-import-type TransportDocumentsController_findAllQuery from Types
 * @phpstan-import-type TransportDocumentsController_findAllResponse from Types
 * @phpstan-import-type TransportDocumentsController_findOneResponse from Types
 * @phpstan-import-type TransportDocumentsController_getPdfQuery from Types
 * @phpstan-import-type TransportDocumentsController_getPdfResponse from Types
 * @phpstan-import-type TransportDocumentsController_issueBody from Types
 * @phpstan-import-type TransportDocumentsController_issueResponse from Types
 * @phpstan-import-type TransportDocumentsController_updateBody from Types
 * @phpstan-import-type TransportDocumentsController_updateResponse from Types
 */
final class TransportDocuments extends Resource
{
    /**
     * Creates a draft. `movementStartTime` must be in the future.
     *
     * @param TransportDocumentsController_createBody $body
     * @param RequestOptions $options
     * @return TransportDocumentsController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_create', ['body' => $body], $options);
    }

    /**
     * @param TransportDocumentsController_findAllQuery $query
     * @param RequestOptions $options
     * @return TransportDocumentsController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_findAll', ['query' => $query], $options);
    }

    /**
     * Every transport document matching the filters, fetching pages as you iterate.
     *
     * @param TransportDocumentsController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, TransportDocumentResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, TransportDocumentResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('TransportDocumentsController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return TransportDocumentsController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param TransportDocumentsController_updateBody $body
     * @param RequestOptions $options
     * @return TransportDocumentsController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * @param RequestOptions $options
     * @return TransportDocumentsController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_delete', ['path' => ['id' => $id]], $options);
    }

    /**
     * Issues the document and reports it to the AT. **Fiscal and irreversible.**
     *
     * @param TransportDocumentsController_issueBody $body
     * @param RequestOptions $options
     * @return TransportDocumentsController_issueResponse
     */
    public function issue(string $id, array $body = [], array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_issue', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * Cancels an issued document at the AT. **Fiscal and irreversible.**
     *
     * @param RequestOptions $options
     * @return TransportDocumentsController_cancelResponse
     */
    public function cancel(string $id, array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_cancel', ['path' => ['id' => $id]], $options);
    }

    /**
     * Link to the document's PDF. Use `downloadPdf` for the bytes.
     *
     * @param TransportDocumentsController_getPdfQuery $query
     * @param RequestOptions $options
     * @return TransportDocumentsController_getPdfResponse
     */
    public function pdf(string $id, array $query = [], array $options = []): array
    {
        return $this->client->call('TransportDocumentsController_getPdf', ['path' => ['id' => $id], 'query' => $query], $options);
    }

    /**
     * The document's PDF file, as a string of bytes.
     *
     * @param TransportDocumentsController_getPdfQuery $query
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
