# FIZ SDK for PHP

Issue **AT-certified invoices in Portugal** from your code. FIZ is invoicing
software certified by the Autoridade Tributária (No. 3041): it numbers each
document in its series, adds the ATCUD and the QR code, reports it to the AT
when it is issued and returns the number and the PDF.

This is the official PHP client for the [FIZ API](https://api.fiz.co): every
operation, with PHPStan/Psalm array shapes generated from the API's OpenAPI
document, and safe retries built in.

[Português](#português) · [API reference](https://api.fiz.co) ·
[Quickstart](https://api.fiz.co/docs/quickstart) ·
[Changelog](https://api.fiz.co/docs/changelog)

```bash
composer require fiz-co/sdk
```

PHP 8.1 or later (CI tests 8.1 to 8.4). It uses Guzzle 7 by default and
accepts any PSR-18 client. An API key acts for the whole company: keep it on
the server, in the environment, never in a template or front-end code.

## Quickstart

Run it with a **sandbox key**: create one at [app.fiz.co → Settings → Integrations](https://app.fiz.co/settings/integrations)
and put it in `FIZ_API_KEY`. A key starting with `fiz_test_` sends documents
to the AT test services, with no fiscal effect. Both steps below stop on any
other key, because the second one issues a real invoice with a production key.

**Step 1: a draft and its PDF.** A draft has no number and no fiscal effect.

```php
<?php
require 'vendor/autoload.php';

use Fiz\Fiz;

$fiz = new Fiz(); // reads FIZ_API_KEY
if ($fiz->environment !== 'sandbox') {
    throw new RuntimeException('Use a sandbox key (fiz_test_…) for this walkthrough.');
}

// Which company is this, and can it issue yet?
$company = $fiz->company->retrieve();
if (!$company['readiness']['ready']) {
    print_r($company['readiness']['reasons']);
}
// Every invoice states one of the company's activity codes (CAE).
$cae = $company['cae'][0] ?? null;
if ($cae === null) {
    throw new RuntimeException('The company has no CAE yet: add its activity in FIZ first.');
}

$customer = $fiz->customers->create(['name' => 'Example customer', 'country' => 'PT']);
$item = $fiz->items->create([
    'name' => 'Example service',
    'type' => 'SERVICE',
    'unitPrice' => 10,
    'vatRate' => 'NORMAL',
]);
$draft = $fiz->invoices->create([
    'type' => 'INVOICE',
    'cae' => $cae,
    'customerId' => $customer['id'],
    'items' => [['id' => $item['id'], 'quantity' => 1]],
]);
file_put_contents('draft.pdf', $fiz->invoices->downloadPdf($draft['id']));
// The revision pins what you are about to look at. Without one there is nothing
// to check the issue against: stop rather than issue whatever the draft is by then.
if ($draft['updatedAt'] === null) {
    throw new RuntimeException('The draft came back without its revision.');
}
echo "Look at draft.pdf, then issue: {$draft['id']} {$draft['updatedAt']}\n";
```

**Step 2, once you have looked at the PDF: issue that draft.** Issuing is
fiscal and irreversible with a production key: numbered, ATCUD, reported to
the AT. `expectedUpdatedAt` issues exactly the draft you looked at, and the
API refuses it if anyone changed it since.

```php
<?php
require 'vendor/autoload.php';

use Fiz\Fiz;

$fiz = new Fiz();
if ($fiz->environment !== 'sandbox') {
    throw new RuntimeException('Use a sandbox key (fiz_test_…) for this walkthrough.');
}

// What step 1 printed.
$draftId = 'the draft id';
$revision = 'the revision';
$invoice = $fiz->invoices->issue($draftId, ['expectedUpdatedAt' => $revision]);
echo $invoice['number'], ' ', $invoice['atcud'], "\n";
```

The same two steps as one runnable script are in
[`examples/quickstart.php`](examples/quickstart.php): its first run creates
the draft and saves its PDF, and a second run with `--issue` issues that draft.

## What it covers

| | |
|---|---|
| `$fiz->invoices` | invoices, simplified invoices, invoice-receipts: `create`, `list`, `listAll`, `retrieve`, `update`, `delete`, `issue`, `pay`, `cancel`, `pdf`, `downloadPdf` |
| `$fiz->creditNotes` | full or per-line credit notes (issued at once unless `'issue' => false`) |
| `$fiz->recurringInvoices` | invoices FIZ issues on a schedule |
| `$fiz->proposals` | pro formas (no fiscal effect) |
| `$fiz->transportDocuments` | guias de transporte (GR, GT, GA, GC, GD) |
| `$fiz->customers`, `$fiz->items` | customers and the item catalogue |
| `$fiz->series`, `$fiz->templates` | invoice series, PDF templates |
| `$fiz->company`, `$fiz->vat` | the company and issuance readiness, VAT rules |
| `$fiz->expenses`, `$fiz->bank` | e-Fatura expenses, bank transactions |
| `$fiz->exports` | SAF-T PT export |

Methods take and return associative arrays shaped as the API document
describes them. Methods marked **fiscal and irreversible** in their
documentation (`issue`, `pay`, `cancel`, credit notes) report to the AT when
used with a production key.

Any operation of the API is also reachable by its OpenAPI `operationId`
(its response typed per operation for PHPStan), including ones newer than
the SDK's resource methods:

```php
$series = $fiz->call('SeriesController_getAll');
```

## Errors

Every failed request throws a `FizApiException` subclass. Branch on `status`
and `errorCode` (the API's `code`); the message is for people and its wording
may change.

```php
use Fiz\Exception\FizApiException;
use Fiz\Exception\FizNotFoundException;

try {
    $invoice = $fiz->invoices->retrieve($id);
} catch (FizNotFoundException $error) {
    // 404
    throw $error;
} catch (FizApiException $error) {
    error_log("{$error->status} {$error->errorCode}: " . implode('; ', $error->messages));
    error_log("Quote to support: {$error->requestId}");
    throw $error;
}
```

| Class (`Fiz\Exception\`) | Status |
|---|---|
| `FizBadRequestException` | 400 — refused as invalid; `messages` has one line per field |
| `FizAuthenticationException` | 401 — key or token missing, invalid or revoked |
| `FizPermissionException` | 403 — scope, plan or permission |
| `FizNotFoundException` | 404 |
| `FizConflictException` | 409 — see [Retries](#retries-and-idempotency) |
| `FizUnprocessableEntityException` | 422 — Idempotency-Key reused with a different request |
| `FizRateLimitException` | 429 |
| `FizServerException` | 5xx |
| `FizConnectionException`, `FizTimeoutException` | no complete response |

All of them extend `FizException`. A `FizApiException` also carries `body`
(the parsed response), `headers` (names in lower case) and `idempotencyKey`;
`getCode()` returns the HTTP status, because PHP reserves an exception's
`$code` for an integer.

## Retries and idempotency

Every write carries an `Idempotency-Key`. The API remembers it for 30 days
and answers a repeat with the original response, so a timeout never costs a
duplicate invoice. The SDK generates one per call (a UUID) and reuses it for
its own retries. Pass yours to make retries safe across restarts too, for
example the id of the order the invoice is for:

```php
$fiz->invoices->create($draft, ['idempotencyKey' => "order-{$order['id']}-invoice"]);
```

The SDK retries (default `'maxRetries' => 2`) only what is safe to repeat:

- no response at all, on a read or a write carrying a key;
- `429`, after `Retry-After` (a wait longer than a minute is not taken: the exception is thrown);
- `409` with `Retry-After`: the first attempt is still running, and the same
  key returns its result;
- `502`, `503`, `504` on a read.

It does **not** retry a `409` without `Retry-After` or a `5xx` on a write:
the first attempt may have taken effect. Check whether it did (list the
documents), then repeat with a **new** key. `$error->idempotencyKey` tells
you which key was spent.

## Pagination

`list` returns one page. `listAll` is a generator that walks every page as
you iterate:

```php
foreach ($fiz->invoices->listAll(['status' => 'ISSUED']) as $invoice) {
    echo $invoice['number'], "\n";
}
```

## OAuth apps

A [registered app](https://api.fiz.co/docs/apps) calls the API on behalf of
the companies that installed it, with an OAuth access token:

```php
$fiz = new Fiz(['accessToken' => $accessToken]);
```

Getting and refreshing the token is your app's part of the flow; see the
[partner guide](https://api.fiz.co/docs/apps).

## Options

```php
$fiz = new Fiz([
    'apiKey' => 'fiz_…',           // default: the FIZ_API_KEY environment variable
    // 'accessToken' => '…',       // instead of apiKey
    'baseUrl' => 'https://api.fiz.co',
    'maxRetries' => 2,
    'timeout' => 60,               // per attempt, seconds
    // 'httpClient' => $client,    // any PSR-18 client (below)
    'appInfo' => ['name' => 'my-shop', 'version' => '1.2.0'], // added to the User-Agent
    'onResponse' => function (array $response): void {         // every response
        error_log("{$response['operationId']} {$response['status']} {$response['requestId']}");
    },
]);
```

`onResponse` is called with every response to an API operation, retries
included (the file download behind `downloadPdf` is not an operation and is
not reported). It receives the operation, status, `requestId`, the
`Idempotency-Key`, whether the API replayed an earlier result, the attempt
number and the time to the response in milliseconds: enough to log the
request id of every invoice you issue. Whatever it throws is ignored.

Each method also takes per-call options as its last argument:
`idempotencyKey`, `maxRetries`, `timeout` and `headers`. An option the SDK
does not know is refused rather than ignored.

## HTTP client

By default the SDK sends requests with its own Guzzle client. Pass a Guzzle
client of yours to add a proxy or middleware; the SDK still sets the timeout
and turns redirects off on every request. A file the API links to on another
host (where a PDF is stored) is fetched with a Guzzle client of the SDK's own,
never yours, so nothing configured on yours (headers, auth, cookies, query,
middleware) reaches that host; cURL still honours the `https_proxy`
environment variable:

```php
use GuzzleHttp\Client;

$fiz = new Fiz(['httpClient' => new Client(['proxy' => 'http://proxy.example.com:3128'])]);
```

Any other [PSR-18](https://www.php-fig.org/psr/psr-18/) client works too
(Symfony's `Psr18Client`, a framework's own): pass it as `httpClient`, and
PSR-17 factories as `requestFactory` and `streamFactory` if you do not want
Guzzle's. Configure it **not to follow redirects** (with Symfony,
`'max_redirects' => 0`), because a redirect would carry the API key to
wherever it points, and give it its own timeout: `timeout` applies to Guzzle
clients only. Files on another host are fetched with the SDK's own Guzzle client, not
this one.

## Static analysis

Request and response shapes come from the API's OpenAPI document, as
PHPStan/Psalm type aliases in `Fiz\Generated\Types`: every schema by name,
common ones under short names (`Invoice`, `Customer`, `Item`, `Company`, …),
and per operation `<operationId>Body`, `<operationId>Query` and
`<operationId>Response`:

```php
use Fiz\Generated\Types;

/**
 * @phpstan-import-type Invoice from Types
 * @phpstan-import-type InvoicesController_createBody from Types
 */
final class InvoiceSubject
{
    /** @param Invoice $invoice */
    public static function of(array $invoice): string
    {
        return 'Invoice ' . ($invoice['number'] ?? 'draft');
    }

    /** @param InvoicesController_createBody $draft */
    public static function lines(array $draft): int
    {
        return count($draft['items']);
    }
}
```

Responses are decoded into PHP arrays, so an empty JSON object comes back as
`[]`. A request body is always sent as an object, but where you send back a
nested object that was empty, pass `new \stdClass()` for it.

## Coding agents

Using Claude Code, Cursor or Codex? Point the agent at
[`api.fiz.co/llms.txt`](https://api.fiz.co/llms.txt): it routes the agent by
task and states the fiscal rules first. The FIZ connector for Claude and
ChatGPT is described at [api.fiz.co/docs/mcp](https://api.fiz.co/docs/mcp).

## Development

```bash
composer install
composer update-spec   # download https://api.fiz.co/-json and regenerate
composer check         # code style, PHPStan, README examples, unit tests
FIZ_TEST_API_KEY=fiz_test_… composer test:sandbox
```

`src/Generated/` is generated from `openapi/openapi.json` by
`scripts/generate.php`; never edit it by hand.

**Releasing.** Packagist builds every version from a git tag, so there is no
publish step. Once, after the repository is public: submit it at
[packagist.org](https://packagist.org/packages/submit) and connect the
GitHub integration, so that a new tag reaches Packagist by itself. For each
release, set the version in `Fiz::VERSION` (`src/Fiz.php`) and add it to
`CHANGELOG.md` (the tests hold the two equal), merge, and push a tag
(`git tag v0.1.1 && git push origin v0.1.1`). CI checks that the tag matches
`Fiz::VERSION`. When the API adds an operation, `composer test` fails until a
resource method wraps it.

## Português

**Faturação certificada em Portugal a partir do seu código.** O FIZ é um
programa de faturação certificado pela AT (n.º 3041): numera cada documento
na série, junta o ATCUD e o código QR, comunica-o à AT no momento da emissão
e devolve o número e o PDF. Este é o cliente oficial da
[API FIZ](https://api.fiz.co/?lang=pt) para PHP.

```bash
composer require fiz-co/sdk
```

1. Crie uma chave API em [app.fiz.co → Definições → Integrações](https://app.fiz.co/settings/integrations)
   e guarde-a em `FIZ_API_KEY`. Uma chave `fiz_test_` usa o **sandbox**:
   serviços de teste da AT, sem efeito fiscal.
2. Siga o exemplo do [Quickstart](#quickstart) com uma chave de sandbox:
   cliente e artigo → rascunho e PDF → emissão. Um rascunho não tem efeito
   fiscal; com uma chave de produção, a emissão é irreversível e comunicada à
   AT.
3. Cada escrita leva uma `Idempotency-Key`, reutilizada nas repetições
   automáticas: um timeout nunca dá origem a uma fatura duplicada.

As exceções trazem `status`, `errorCode` e `requestId` (indique-o ao
suporte). Guia completo em [api.fiz.co/docs/quickstart?lang=pt](https://api.fiz.co/docs/quickstart?lang=pt).

## License

MIT
