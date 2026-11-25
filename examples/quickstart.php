<?php

/**
 * The quickstart in two runs, so that what gets issued is the draft you looked at.
 *
 *   FIZ_API_KEY=fiz_test_… php examples/quickstart.php
 *     → checks the company, creates a customer, an item and a draft, saves
 *       draft.pdf and prints the draft's id and revision.
 *
 *   FIZ_API_KEY=fiz_test_… php examples/quickstart.php --issue <draft id> <revision>
 *     → issues THAT draft at THAT revision (its updatedAt when the PDF was
 *       made): if anyone changed it since, the API refuses instead of issuing
 *       a version you have not seen.
 *
 * With a sandbox key (fiz_test_…) the invoice goes to the AT test services
 * and has no fiscal effect. With a production key, issuing is fiscal and
 * irreversible, so the second run asks you to add --production as well.
 */

declare(strict_types=1);

use Fiz\Exception\FizApiException;
use Fiz\Exception\FizConflictException;
use Fiz\Fiz;

require dirname(__DIR__) . '/vendor/autoload.php';

function createDraft(Fiz $fiz): void
{
    // 1. Which company is this key for, and can it issue yet?
    $company = $fiz->company->retrieve();
    printf(
        "%s (%s), ready to issue: %s\n",
        $company['name'],
        $company['environment'],
        $company['readiness']['ready'] ? 'yes' : 'no',
    );
    foreach ($company['readiness']['reasons'] as $reason) {
        echo "  - {$reason['code']}: {$reason['actionUrl']}\n";
    }
    // Every invoice states one of the company's activity codes (CAE).
    $cae = $company['cae'][0] ?? null;
    if ($cae === null) {
        echo "The company has no CAE yet: add its activity in FIZ first.\n";
        return;
    }

    // 2. A customer and an item. A fixed Idempotency-Key per record makes a
    //    re-run return the same records instead of creating new ones.
    $customer = $fiz->customers->create(
        ['name' => 'Example customer', 'country' => 'PT'],
        ['idempotencyKey' => 'quickstart-customer'],
    );
    $item = $fiz->items->create(
        ['name' => 'Example service', 'type' => 'SERVICE', 'unitPrice' => 10, 'vatRate' => 'NORMAL'],
        ['idempotencyKey' => 'quickstart-item'],
    );

    // 3. A draft: no number, no fiscal effect. Then its PDF, to look at.
    $draft = $fiz->invoices->create([
        'type' => 'INVOICE',
        'cae' => $cae,
        'customerId' => $customer['id'],
        'items' => [['id' => $item['id'], 'quantity' => 1]],
    ]);
    // The issue command below is printed only once the PDF to look at is on
    // disk: a failed write could leave an earlier draft's PDF in its place.
    if (file_put_contents('draft.pdf', $fiz->invoices->downloadPdf($draft['id'])) === false) {
        echo "Could not save draft.pdf, so there is nothing to look at: no issue command.\n";
        return;
    }
    // The revision is what makes the second run issue exactly this draft.
    // Without one there is nothing to check against, so there is no command.
    if ($draft['updatedAt'] === null || $draft['updatedAt'] === '') {
        echo "Draft {$draft['id']} saved to draft.pdf, but the API returned no revision for it.\n";
        echo "Issue it from the FIZ app after reviewing it, or create it again.\n";
        return;
    }
    echo "Draft {$draft['id']} saved to draft.pdf. Look at it, then issue it with:\n";
    echo "  php examples/quickstart.php --issue {$draft['id']} {$draft['updatedAt']}\n";
}

function issueDraft(Fiz $fiz, string $id, string $revision, bool $production): void
{
    if ($id === '' || $revision === '') {
        echo "Usage: --issue <draft id> <revision>, as the first run printed them.\n";
        return;
    }
    if ($fiz->environment === 'production' && !$production) {
        echo "This is a production key: issuing reports a real invoice to the AT.\n";
        echo "Add --production to issue draft $id.\n";
        return;
    }
    // 4. Issue the draft at the revision you looked at: numbered, ATCUD and QR
    //    code, reported to the AT. Not re-read here on purpose: the revision
    //    from the first run is what makes a later change refuse the issue.
    try {
        $issued = $fiz->invoices->issue($id, ['expectedUpdatedAt' => $revision]);
        echo "Issued {$issued['number']}, ATCUD {$issued['atcud']}\n";
    } catch (FizConflictException $error) {
        if ($error->errorCode !== 'INVOICE_DRAFT_MODIFIED') {
            throw $error;
        }
        echo "The draft changed after its PDF was made, so it was not issued. Run the first step again.\n";
    }
}

/** @var list<string> $args */
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));
$issueAt = array_search('--issue', $args, true);

try {
    $fiz = new Fiz(); // reads FIZ_API_KEY
    if ($issueAt === false) {
        createDraft($fiz);
    } else {
        issueDraft($fiz, $args[$issueAt + 1] ?? '', $args[$issueAt + 2] ?? '', in_array('--production', $args, true));
    }
} catch (FizApiException $error) {
    fwrite(STDERR, "{$error->status} {$error->errorCode}: " . implode('; ', $error->messages) . "\n");
    fwrite(STDERR, "Request id for support: {$error->requestId}\n");
    exit(1);
}
