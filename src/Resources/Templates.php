<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * PDF templates: build, validate, preview and install.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type TemplatesController_createBody from Types
 * @phpstan-import-type TemplatesController_createResponse from Types
 * @phpstan-import-type TemplatesController_deleteResponse from Types
 * @phpstan-import-type TemplatesController_fieldsResponse from Types
 * @phpstan-import-type TemplatesController_findOneResponse from Types
 * @phpstan-import-type TemplatesController_getAllQuery from Types
 * @phpstan-import-type TemplatesController_getAllResponse from Types
 * @phpstan-import-type TemplatesController_previewBody from Types
 * @phpstan-import-type TemplatesController_previewResponse from Types
 * @phpstan-import-type TemplatesController_starterResponse from Types
 * @phpstan-import-type TemplatesController_startersResponse from Types
 * @phpstan-import-type TemplatesController_updateBody from Types
 * @phpstan-import-type TemplatesController_updateResponse from Types
 * @phpstan-import-type TemplatesController_validateBody from Types
 * @phpstan-import-type TemplatesController_validateResponse from Types
 */
final class Templates extends Resource
{
    /**
     * @param TemplatesController_getAllQuery $query
     * @param RequestOptions $options
     * @return TemplatesController_getAllResponse
     */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->call('TemplatesController_getAll', ['query' => $query], $options);
    }

    /**
     * @param TemplatesController_createBody $body
     * @param RequestOptions $options
     * @return TemplatesController_createResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('TemplatesController_create', ['body' => $body], $options);
    }

    /**
     * @param RequestOptions $options
     * @return TemplatesController_findOneResponse
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->client->call('TemplatesController_findOne', ['path' => ['id' => $id]], $options);
    }

    /**
     * Replaces the template.
     *
     * @param TemplatesController_updateBody $body
     * @param RequestOptions $options
     * @return TemplatesController_updateResponse
     */
    public function update(string $id, array $body, array $options = []): array
    {
        return $this->client->call('TemplatesController_update', ['path' => ['id' => $id], 'body' => $body], $options);
    }

    /**
     * @param RequestOptions $options
     * @return TemplatesController_deleteResponse
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->client->call('TemplatesController_delete', ['path' => ['id' => $id]], $options);
    }

    /**
     * The fields a template can print.
     *
     * @param RequestOptions $options
     * @return TemplatesController_fieldsResponse
     */
    public function fields(array $options = []): array
    {
        return $this->client->call('TemplatesController_fields', [], $options);
    }

    /**
     * @param RequestOptions $options
     * @return TemplatesController_startersResponse
     */
    public function starters(array $options = []): array
    {
        return $this->client->call('TemplatesController_starters', [], $options);
    }

    /**
     * @param RequestOptions $options
     * @return TemplatesController_starterResponse
     */
    public function starter(string $starterId, array $options = []): array
    {
        return $this->client->call('TemplatesController_starter', ['path' => ['starterId' => $starterId]], $options);
    }

    /**
     * Answers as `create` would, without storing anything.
     *
     * @param TemplatesController_validateBody $body
     * @param RequestOptions $options
     * @return TemplatesController_validateResponse
     */
    public function validate(array $body, array $options = []): array
    {
        return $this->client->call('TemplatesController_validate', ['body' => $body], $options);
    }

    /**
     * Renders the template on a sample document.
     *
     * @param TemplatesController_previewBody $body
     * @param RequestOptions $options
     * @return TemplatesController_previewResponse
     */
    public function preview(array $body, array $options = []): array
    {
        return $this->client->call('TemplatesController_preview', ['body' => $body], $options);
    }
}
