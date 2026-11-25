<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;
use Fiz\Pagination;

/**
 * Pro formas: numbered at creation, never reported to the AT, no fiscal
 * effect.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type ProposalResponseDto from Types
 * @phpstan-import-type ProposalsController_cancelResponse from Types
 * @phpstan-import-type ProposalsController_createBody from Types
 * @phpstan-import-type ProposalsController_createResponse from Types
 * @phpstan-import-type ProposalsController_deleteResponse from Types
 * @phpstan-import-type ProposalsController_findAllListAllQuery from Types
 * @phpstan-import-type ProposalsController_findAllQuery from Types
 * @phpstan-import-type ProposalsController_findAllResponse from Types
 * @phpstan-import-type ProposalsController_findOneResponse from Types
 * @phpstan-import-type ProposalsController_getPdfQuery from Types
 * @phpstan-import-type ProposalsController_getPdfResponse from Types
 */
final class Proposals extends Resource
{
    /**
     * @param ProposalsController_createBody $body
     * @param RequestOptions $options
     * @return ProposalsController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('ProposalsController_create', ['body' => $body], $options);
    }

    /**
     * @param ProposalsController_findAllQuery $query
     * @param RequestOptions $options
     * @return ProposalsController_findAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('ProposalsController_findAll', ['query' => $query], $options);
    }

    /**
     * Every pro forma matching the filters, fetching pages as you iterate.
     *
     * @param ProposalsController_findAllListAllQuery $query
     * @param RequestOptions $options
     * @return \Generator<int, ProposalResponseDto, mixed, void>
     */
    public function listAll(array $query = [], array $options = []): \Generator
    {
        // Declared again: PHPStan widens the enums of a type it infers.
        /** @var \Generator<int, ProposalResponseDto, mixed, void> */
        return Pagination::byOffset(
            fn (array $page): array => $this->client->call('ProposalsController_findAll', ['query' => $page], $options),
            $query,
        );
    }

    /**
     * @param RequestOptions $options
     * @return ProposalsController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('ProposalsController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param RequestOptions $options
     * @return ProposalsController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('ProposalsController_delete', ['path' => ['id' => $id]], $options);
    }

    /**
     * @param RequestOptions $options
     * @return ProposalsController_cancelResponse
     */
    public function cancel(string $id, array $options = []): array
    {
        return $this->client->call('ProposalsController_cancel', ['path' => ['id' => $id]], $options);
    }

    /**
     * Link to the pro forma's PDF. Use `downloadPdf` for the bytes.
     *
     * @param ProposalsController_getPdfQuery $query
     * @param RequestOptions $options
     * @return ProposalsController_getPdfResponse
     */
    public function pdf(string $id, array $query = [], array $options = []): array
    {
        return $this->client->call('ProposalsController_getPdf', ['path' => ['id' => $id], 'query' => $query], $options);
    }

    /**
     * The pro forma's PDF file, as a string of bytes.
     *
     * @param ProposalsController_getPdfQuery $query
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
