<?php

declare(strict_types=1);

namespace Fiz\Resources;

use Fiz\FizClient;
use Fiz\Generated\Types;

/**
 * Credit notes on an issued invoice, total or per line.
 *
 * @phpstan-import-type RequestOptions from FizClient
 * @phpstan-import-type InvoicesController_createCreditNoteBody from Types
 * @phpstan-import-type InvoicesController_createCreditNoteResponse from Types
 */
final class CreditNotes extends Resource
{
    /**
     * Creates a credit note. Without `items` it reverses the invoice in full;
     * with `items` it changes the quantity or price of the given lines.
     *
     * **Issued at once by default**: without `'issue' => false` the note is
     * numbered and reported to the AT in this call, which with a production key
     * is fiscal and irreversible. Pass `'issue' => false` for a draft, then
     * `$fiz->invoices->issue($note['id'], ['expectedUpdatedAt' => …])` once it
     * has been reviewed.
     *
     * @param InvoicesController_createCreditNoteBody $body
     * @param RequestOptions $options
     * @return InvoicesController_createCreditNoteResponse
     */
    public function create(array $body, array $options = []): array
    {
        return $this->client->call('InvoicesController_createCreditNote', ['body' => $body], $options);
    }
}
