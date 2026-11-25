<?php

declare(strict_types=1);

namespace Fiz\Tests\Sandbox;

use Fiz\Exception\FizNotFoundException;
use Fiz\Fiz;
use Fiz\FizClient;
use PHPUnit\Framework\TestCase;

/**
 * End to end against the FIZ API sandbox, and a contract test: every
 * response the API sends is validated against the API document
 * (tests/Sandbox/Contract.php), so a schema that rejects what the API really
 * returns fails here instead of in a client's code.
 *
 * Runs only with a sandbox key (FIZ_TEST_API_KEY=fiz_test_…):
 * `composer test:sandbox`. Sandbox documents go to the AT test services and
 * have no fiscal effect; a production key is refused before any request.
 *
 * The tests run in order and share what they created.
 */
final class WalkthroughTest extends TestCase
{
    private static Fiz $fiz;
    private static Contract $contract;
    private static string $tag;
    /** @var array{customerId?: string, itemId?: string, invoiceId?: string} */
    private static array $state = [];

    public static function setUpBeforeClass(): void
    {
        $apiKey = getenv('FIZ_TEST_API_KEY');
        if ($apiKey === false || $apiKey === '') {
            self::markTestSkipped('Set FIZ_TEST_API_KEY (a fiz_test_ key) to run against the sandbox.');
        }
        if (!str_starts_with($apiKey, 'fiz_test_')) {
            throw new \RuntimeException('FIZ_TEST_API_KEY must be a sandbox key (fiz_test_…)');
        }
        $baseUrl = rtrim(getenv('FIZ_BASE_URL') ?: FizClient::DEFAULT_BASE_URL, '/');
        $uri = parse_url($baseUrl);
        self::$contract = new Contract($uri['scheme'] . '://' . $uri['host'] . (isset($uri['port']) ? ':' . $uri['port'] : ''));
        self::$fiz = new Fiz([
            'apiKey' => $apiKey,
            'baseUrl' => $baseUrl,
            'httpClient' => self::$contract->client(),
            'appInfo' => ['name' => 'fiz-sdk-php-ci'],
        ]);
        self::$tag = (string) (int) (microtime(true) * 1000);
        self::$state = [];
    }

    private static function key(string $step): string
    {
        return 'sdk-php-ci-' . self::$tag . "-$step";
    }

    public function testReachesTheSandbox(): void
    {
        $company = self::$fiz->company->retrieve();
        self::assertSame('sandbox', self::$fiz->environment);
        self::assertSame('sandbox', $company['environment']);
    }

    public function testReadsTheLists(): void
    {
        self::$fiz->invoices->list(['limit' => 5]);
        self::$fiz->customers->list(['limit' => 5]);
        self::$fiz->items->list(['limit' => 5]);
        self::$fiz->proposals->list(['limit' => 5]);
        self::$fiz->recurringInvoices->list(['limit' => 5]);
        self::$fiz->transportDocuments->list(['limit' => 5]);
        self::$fiz->series->list();
        self::$fiz->templates->list();
        $this->addToAssertionCount(1);
    }

    public function testCreatesUpdatesPrintsAndIssuesAnInvoice(): void
    {
        $company = self::$fiz->company->retrieve();
        $cae = $company['cae'][0] ?? null;
        self::assertNotNull($cae, 'The sandbox company has no CAE');
        $customer = self::$fiz->customers->create(
            ['name' => 'SDK sandbox customer ' . self::$tag, 'country' => 'PT'],
            ['idempotencyKey' => self::key('customer')],
        );
        $item = self::$fiz->items->create(
            ['name' => 'SDK sandbox service ' . self::$tag, 'type' => 'SERVICE', 'unitPrice' => 10, 'vatRate' => 'NORMAL'],
            ['idempotencyKey' => self::key('item')],
        );
        self::$state['customerId'] = $customer['id'];
        self::$state['itemId'] = $item['id'];
        self::$fiz->customers->retrieve($customer['id']);
        self::$fiz->items->retrieve($item['id']);

        $created = self::$fiz->invoices->create(
            ['type' => 'INVOICE', 'cae' => $cae, 'customerId' => $customer['id'], 'items' => [['id' => $item['id'], 'quantity' => 2]]],
            ['idempotencyKey' => self::key('draft')],
        );
        self::assertNull($created['number']);
        $updated = self::$fiz->invoices->update($created['id'], ['notes' => 'Updated by the SDK sandbox test']);
        self::$fiz->invoices->retrieve($created['id']);
        $pdf = self::$fiz->invoices->downloadPdf($created['id']);
        self::assertSame('%PDF-', substr($pdf, 0, 5));

        if (!$company['readiness']['ready']) {
            self::markTestIncomplete('The sandbox company is not ready to issue; issuing skipped.');
        }
        // A revision is required: issuing without one would skip the check
        // that the draft is the one just updated.
        self::assertNotEmpty($updated['updatedAt']);
        $issued = self::$fiz->invoices->issue($created['id'], ['expectedUpdatedAt' => $updated['updatedAt'] ?? 'missing']);
        self::assertNotEmpty($issued['number']);
        self::assertNotEmpty($issued['atcud']);
        self::$state['invoiceId'] = $created['id'];
        self::$fiz->invoices->retrieve($created['id']);
    }

    public function testRaisesAndIssuesAPartialCreditNote(): void
    {
        if (!isset(self::$state['invoiceId'], self::$state['itemId'])) {
            self::markTestSkipped('No issued invoice to credit.');
        }
        $note = self::$fiz->creditNotes->create(
            [
                'parentInvoiceId' => self::$state['invoiceId'],
                'reasonCode' => 'RETURN_GOODS_SERVICES',
                'items' => [['itemId' => self::$state['itemId'], 'quantity' => 1]],
                'issue' => false,
            ],
            ['idempotencyKey' => self::key('credit-note')],
        );
        self::assertNull($note['number']);
        $draft = self::$fiz->invoices->retrieve($note['id']);
        self::assertNotEmpty($draft['updatedAt']);
        $issued = self::$fiz->invoices->issue($note['id'], ['expectedUpdatedAt' => $draft['updatedAt'] ?? 'missing']);
        self::assertNotEmpty($issued['number']);
        self::$fiz->invoices->retrieve($note['id']);
    }

    public function testAnswersAMissingRecordWithTheDocumented404(): void
    {
        try {
            self::$fiz->invoices->retrieve('000000000000000000000000');
            self::fail('Expected a 404');
        } catch (FizNotFoundException $error) {
            self::assertNotEmpty($error->requestId);
        }
    }

    public function testArchivesTheCustomerAndTheItemItCreated(): void
    {
        if (isset(self::$state['customerId'])) {
            self::$fiz->customers->delete(self::$state['customerId']);
        }
        if (isset(self::$state['itemId'])) {
            self::$fiz->items->delete(self::$state['itemId']);
        }
        $this->addToAssertionCount(1);
    }

    /** Every response of every test above, against the API document. Keep it last. */
    public function testEveryResponseMatchesTheApiDocument(): void
    {
        self::assertSame([], self::$contract->failures);
    }
}
