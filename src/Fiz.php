<?php

declare(strict_types=1);

namespace Fiz;

use Fiz\Generated\Types;
use Fiz\Resources\Bank;
use Fiz\Resources\Company;
use Fiz\Resources\CreditNotes;
use Fiz\Resources\Customers;
use Fiz\Resources\Expenses;
use Fiz\Resources\Exports;
use Fiz\Resources\Invoices;
use Fiz\Resources\Items;
use Fiz\Resources\Proposals;
use Fiz\Resources\RecurringInvoices;
use Fiz\Resources\Series;
use Fiz\Resources\Templates;
use Fiz\Resources\TransportDocuments;
use Fiz\Resources\Vat;

/**
 * Client for the FIZ API: certified invoicing in Portugal.
 *
 * ```php
 * $fiz = new \Fiz\Fiz(); // reads FIZ_API_KEY
 * $draft = $fiz->invoices->create(['type' => 'INVOICE', 'cae' => $cae, 'customerId' => $customerId, 'items' => $items]);
 * ```
 *
 * @phpstan-import-type FizOptions from FizClient
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type CallArgs from FizClient
 * @phpstan-import-type ResponseMap from Types
 */
final class Fiz
{
    /** The SDK's version, sent in the User-Agent (kept equal to CHANGELOG.md). */
    public const VERSION = '0.1.0';

    public readonly Invoices $invoices;
    public readonly CreditNotes $creditNotes;
    public readonly RecurringInvoices $recurringInvoices;
    public readonly Proposals $proposals;
    public readonly TransportDocuments $transportDocuments;
    public readonly Customers $customers;
    public readonly Items $items;
    public readonly Series $series;
    public readonly Templates $templates;
    public readonly Company $company;
    public readonly Vat $vat;
    public readonly Expenses $expenses;
    public readonly Bank $bank;
    public readonly Exports $exports;
    /** `sandbox` when the key starts with `fiz_test_` (no fiscal effect), else `production`. */
    public readonly string $environment;
    private readonly FizClient $client;

    /**
     * @param FizOptions $options
     */
    public function __construct(array $options = [])
    {
        $this->client = new FizClient($options);
        $this->environment = $this->client->environment;
        $this->invoices = new Invoices($this->client);
        $this->creditNotes = new CreditNotes($this->client);
        $this->recurringInvoices = new RecurringInvoices($this->client);
        $this->proposals = new Proposals($this->client);
        $this->transportDocuments = new TransportDocuments($this->client);
        $this->customers = new Customers($this->client);
        $this->items = new Items($this->client);
        $this->series = new Series($this->client);
        $this->templates = new Templates($this->client);
        $this->company = new Company($this->client);
        $this->vat = new Vat($this->client);
        $this->expenses = new Expenses($this->client);
        $this->bank = new Bank($this->client);
        $this->exports = new Exports($this->client);
    }

    /**
     * Calls any operation by its OpenAPI `operationId`, typed from the spec.
     *
     * @template K of key-of<ResponseMap>
     * @param K $operationId
     * @param CallArgs $args
     * @param RequestOptions $options
     * @return ResponseMap[K]
     */
    public function call(string $operationId, array $args = [], array $options = []): mixed
    {
        return $this->client->call($operationId, $args, $options);
    }
}
