<?php

declare(strict_types=1);

namespace Fiz\Tests\Unit;

use Fiz\Exception\FizApiException;
use Fiz\Exception\FizAuthenticationException;
use Fiz\Exception\FizBadRequestException;
use Fiz\Exception\FizConflictException;
use Fiz\Exception\FizConnectionException;
use Fiz\Exception\FizException;
use Fiz\Exception\FizNotFoundException;
use Fiz\Exception\FizRateLimitException;
use Fiz\Exception\FizServerException;
use Fiz\Exception\FizTimeoutException;
use Fiz\Fiz;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private const CUSTOMER = ['name' => 'Example customer', 'country' => 'PT'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var array{0: string|false, 1: mixed, 2: mixed} */
    private array $savedEnv;

    protected function setUp(): void
    {
        $this->savedEnv = [getenv('FIZ_API_KEY'), $_ENV['FIZ_API_KEY'] ?? null, $_SERVER['FIZ_API_KEY'] ?? null];
        self::setEnvKey(null);
    }

    protected function tearDown(): void
    {
        [$env, $envArray, $server] = $this->savedEnv;
        putenv($env === false ? 'FIZ_API_KEY' : "FIZ_API_KEY=$env");
        unset($_ENV['FIZ_API_KEY'], $_SERVER['FIZ_API_KEY']);
        if ($envArray !== null) {
            $_ENV['FIZ_API_KEY'] = $envArray;
        }
        if ($server !== null) {
            $_SERVER['FIZ_API_KEY'] = $server;
        }
    }

    private static function setEnvKey(?string $value): void
    {
        unset($_ENV['FIZ_API_KEY'], $_SERVER['FIZ_API_KEY']);
        putenv($value === null ? 'FIZ_API_KEY' : "FIZ_API_KEY=$value");
    }

    /** Runs $call and returns what it threw. */
    private static function thrown(callable $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $error) {
            return $error;
        }
        self::fail('Expected an exception');
    }

    /** A response whose body fails while it is read, as a connection cut mid-body does. */
    private static function cutOff(int $status, array $headers = []): \Closure
    {
        return static fn (): ResponseInterface => new Response(
            $status,
            ['content-type' => 'application/json'] + $headers,
            FnStream::decorate(Utils::streamFor(''), [
                '__toString' => static fn () => throw new \RuntimeException('terminated'),
                'getContents' => static fn () => throw new \RuntimeException('terminated'),
            ]),
        );
    }

    /** Guzzle's report of a transfer cut off after the headers arrived. */
    private static function cutOffByGuzzle(int $status, array $headers = []): \Closure
    {
        return static function (RequestInterface $request) use ($status, $headers): never {
            throw new RequestException(
                'cURL error 18: transfer closed with outstanding read data remaining',
                $request,
                new Response($status, ['content-type' => 'application/json'] + $headers),
                null,
                ['errno' => 18],
            );
        };
    }

    private static function text(int $status, string $body, string $contentType = 'application/json'): \Closure
    {
        return static fn (): ResponseInterface => new Response($status, ['content-type' => $contentType], $body);
    }

    private static function noResponse(string $message = 'cURL error 7: Failed to connect', int $errno = 7): \Closure
    {
        return static function (RequestInterface $request) use ($message, $errno): never {
            throw new ConnectException($message, $request, null, ['errno' => $errno]);
        };
    }

    // --- credentials ---------------------------------------------------------

    public function testSendsTheApiKeyAsXApiKey(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]]);
        $mock->fiz->company->retrieve();
        self::assertSame('fiz_live_key', $mock->requests[0]['headers']['x-api-key']);
        self::assertArrayNotHasKey('authorization', $mock->requests[0]['headers']);
        self::assertSame('https://api.fiz.co/company', $mock->requests[0]['url']);
    }

    public function testSendsAnAccessTokenAsABearerToken(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]], ['apiKey' => null, 'accessToken' => 'fiz_oat_token']);
        $mock->fiz->company->retrieve();
        self::assertSame('Bearer fiz_oat_token', $mock->requests[0]['headers']['authorization']);
        self::assertArrayNotHasKey('x-api-key', $mock->requests[0]['headers']);
    }

    public function testReadsFizApiKeyWhenNoCredentialIsPassed(): void
    {
        self::setEnvKey('fiz_test_from_env');
        $mock = new MockFiz([['status' => 200, 'body' => []]], ['apiKey' => null]);
        $mock->fiz->company->retrieve();
        self::assertSame('fiz_test_from_env', $mock->requests[0]['headers']['x-api-key']);
        self::assertSame('sandbox', $mock->fiz->environment);
    }

    public function testRefusesBothCredentialsAndNone(): void
    {
        self::assertInstanceOf(FizException::class, self::thrown(fn () => new Fiz(['apiKey' => 'a', 'accessToken' => 'b'])));
        self::assertMatchesRegularExpression('/FIZ_API_KEY/', self::thrown(fn () => new Fiz())->getMessage());
        self::assertMatchesRegularExpression('/FIZ_API_KEY/', self::thrown(fn () => new Fiz(['apiKey' => '']))->getMessage());
    }

    public function testRefusesAnUnknownOptionRatherThanIgnoringIt(): void
    {
        self::setEnvKey('fiz_live_from_env');
        $error = self::thrown(fn () => new Fiz(['accesstoken' => 'fiz_oat_x']));
        self::assertInstanceOf(FizException::class, $error);
        self::assertStringContainsString('accesstoken', $error->getMessage());
        $mock = new MockFiz([]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER, ['idempotency_key' => 'k']));
        self::assertStringContainsString('idempotency_key', $error->getMessage());
        self::assertCount(0, $mock->requests);
    }

    public function testReportsTheEnvironmentFromTheKeyPrefix(): void
    {
        self::assertSame('sandbox', (new Fiz(['apiKey' => 'fiz_test_x']))->environment);
        self::assertSame('production', (new Fiz(['apiKey' => 'fiz_live_x']))->environment);
        self::assertSame('production', (new Fiz(['accessToken' => 'fiz_oat_x']))->environment);
    }

    public function testIdentifiesItselfAndTheIntegrationInTheUserAgent(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]], ['appInfo' => ['name' => 'acme-shop', 'version' => '2.1.0']]);
        $mock->fiz->company->retrieve();
        self::assertMatchesRegularExpression(
            '/^fiz-sdk-php\/\d+\.\d+\.\d+ php\/\S+ acme-shop\/2\.1\.0$/',
            $mock->requests[0]['headers']['user-agent'],
        );
    }

    // --- requests ------------------------------------------------------------

    public function testExpandsPathParametersAndEncodesThem(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]]);
        $mock->fiz->invoices->retrieve('a/b');
        self::assertSame('/invoices/a%2Fb', $mock->requests[0]['path']);
    }

    public function testSerialisesQueryParametersRepeatingListsAndSkippingNull(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []], ['status' => 200, 'body' => []]]);
        $mock->fiz->bank->transactions(['connectionId' => 'c1', 'types' => ['Purchase', 'Fee'], 'searchString' => null]);
        $query = $mock->requests[0]['query'];
        self::assertSame(['Purchase', 'Fee'], $query['types']);
        self::assertSame(['c1'], $query['connectionId']);
        self::assertArrayNotHasKey('searchString', $query);

        $mock->fiz->invoices->list([
            'includeATInvoices' => false,
            'fromDate' => new \DateTimeImmutable('2026-11-24 10:00:00', new \DateTimeZone('Europe/Lisbon')),
        ]);
        self::assertSame(['false'], $mock->requests[1]['query']['includeATInvoices']);
        self::assertSame(['2026-11-24T10:00:00.000Z'], $mock->requests[1]['query']['fromDate']);
    }

    public function testSendsAJsonBody(): void
    {
        $mock = new MockFiz([['status' => 201, 'body' => ['id' => 'c1']]]);
        $created = $mock->fiz->customers->create(self::CUSTOMER);
        self::assertSame(['id' => 'c1'], $created);
        self::assertSame('POST', $mock->requests[0]['method']);
        self::assertSame('application/json', $mock->requests[0]['headers']['content-type']);
        self::assertSame(self::CUSTOMER, $mock->requests[0]['body']);
    }

    public function testSendsAnEmptyBodyAsAJsonObject(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => ['id' => 'inv1']]]);
        $mock->fiz->invoices->issue('inv1');
        self::assertSame('{}', $mock->requests[0]['rawBody']);
    }

    public function testUsesAnotherBaseUrl(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]], ['baseUrl' => 'http://localhost:8080/']);
        $mock->fiz->company->retrieve();
        self::assertSame('http://localhost:8080/company', $mock->requests[0]['url']);
    }

    public function testRefusesAnUnknownOperation(): void
    {
        $mock = new MockFiz([]);
        /** @phpstan-ignore argument.type */
        $error = self::thrown(fn () => $mock->fiz->call('NotAnOperation'));
        self::assertInstanceOf(FizException::class, $error);
        self::assertCount(0, $mock->requests);
    }

    public function testAppliesTheTimeoutPerAttemptAndNeverFollowsRedirects(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []], ['status' => 200, 'body' => []]], ['timeout' => 30]);
        $mock->fiz->company->retrieve();
        $mock->fiz->company->retrieve(['timeout' => 5]);
        self::assertSame([30.0, 5.0], array_map(fn ($r) => $r['options']['timeout'], $mock->requests));
        self::assertFalse($mock->requests[0]['options']['allow_redirects']);
        self::assertFalse($mock->requests[0]['options']['http_errors']);
    }

    // --- Idempotency-Key -----------------------------------------------------

    public function testIsGeneratedForAWrite(): void
    {
        $mock = new MockFiz([['status' => 201, 'body' => []]]);
        $mock->fiz->customers->create(self::CUSTOMER);
        self::assertMatchesRegularExpression(self::UUID, $mock->requests[0]['headers']['idempotency-key']);
    }

    public function testIsTheCallersWhenGiven(): void
    {
        $mock = new MockFiz([['status' => 201, 'body' => []]]);
        $mock->fiz->customers->create(self::CUSTOMER, ['idempotencyKey' => 'order-1042-customer']);
        self::assertSame('order-1042-customer', $mock->requests[0]['headers']['idempotency-key']);
    }

    public function testTakesACallersIdempotencyKeyHeaderInAnyCaseAsTheKey(): void
    {
        $mock = new MockFiz([['status' => 201, 'body' => []]]);
        $mock->fiz->customers->create(self::CUSTOMER, ['headers' => ['Idempotency-Key' => 'order-7']]);
        self::assertSame('order-7', $mock->requests[0]['headers']['idempotency-key']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidKeys(): array
    {
        return [
            'an empty option' => [['idempotencyKey' => '']],
            'a blank header' => [['headers' => ['Idempotency-Key' => '   ']]],
            'an empty header' => [['headers' => ['idempotency-key' => '']]],
            'a key with a space' => [['idempotencyKey' => 'order 7']],
            'a key with a trailing newline' => [['idempotencyKey' => "order-7\n"]],
            'a key over 128 characters' => [['idempotencyKey' => str_repeat('k', 129)]],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidKeys')]
    public function testRefusesAnInvalidKeyBeforeSendingAnything(array $options): void
    {
        $mock = new MockFiz([['status' => 201, 'body' => []]]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER, $options));
        self::assertInstanceOf(FizException::class, $error);
        self::assertStringContainsString('idempotencyKey', $error->getMessage());
        self::assertCount(0, $mock->requests);
    }

    public function testIsNotSentOnAReadNorOnAnOperationThatDoesNotTakeOne(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []], ['status' => 200, 'body' => []]]);
        $mock->fiz->invoices->list();
        $mock->fiz->templates->validate(['data' => []], ['headers' => ['Idempotency-Key' => 'k']]);
        self::assertArrayNotHasKey('idempotency-key', $mock->requests[0]['headers']);
        self::assertArrayNotHasKey('idempotency-key', $mock->requests[1]['headers']);
    }

    // --- errors --------------------------------------------------------------

    public function testCarriesStatusCodeMessagesAndTheRequestId(): void
    {
        $mock = new MockFiz([[
            'status' => 404,
            'body' => ['statusCode' => 404, 'message' => 'Invoice not found', 'code' => 'ENTITY_NOT_FOUND'],
            'headers' => ['x-request-id' => 'req-1'],
        ]]);
        $error = self::thrown(fn () => $mock->fiz->invoices->retrieve('x'));
        self::assertInstanceOf(FizNotFoundException::class, $error);
        self::assertInstanceOf(FizApiException::class, $error);
        self::assertSame(404, $error->status);
        self::assertSame(404, $error->getCode());
        self::assertSame('ENTITY_NOT_FOUND', $error->errorCode);
        self::assertSame(['Invoice not found'], $error->messages);
        self::assertSame('req-1', $error->requestId);
        self::assertSame(['req-1'], $error->headers['x-request-id']);
        self::assertSame('GET /invoices/x failed with 404: Invoice not found', $error->getMessage());
    }

    public function testListsEveryValidationMessage(): void
    {
        $mock = new MockFiz([[
            'status' => 400,
            'body' => ['statusCode' => 400, 'message' => ['name must be a string', 'property x should not exist'], 'error' => 'Bad Request'],
        ]]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER));
        self::assertInstanceOf(FizBadRequestException::class, $error);
        self::assertCount(2, $error->messages);
        self::assertNull($error->errorCode);
        self::assertMatchesRegularExpression(self::UUID, (string) $error->idempotencyKey);
    }

    public function testMaps401And5xx(): void
    {
        $mock = new MockFiz([
            ['status' => 401, 'body' => ['statusCode' => 401, 'message' => 'API key is missing']],
            ['status' => 500, 'body' => ['statusCode' => 500, 'message' => 'boom']],
        ]);
        self::assertInstanceOf(FizAuthenticationException::class, self::thrown(fn () => $mock->fiz->company->retrieve()));
        self::assertInstanceOf(FizServerException::class, self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER)));
    }

    // --- retries -------------------------------------------------------------

    public function testRetriesA429AfterRetryAfter(): void
    {
        $mock = new MockFiz([
            ['status' => 429, 'body' => ['statusCode' => 429, 'message' => 'slow down'], 'headers' => ['retry-after' => '2']],
            ['status' => 201, 'body' => ['id' => 'c1']],
        ]);
        self::assertSame(['id' => 'c1'], $mock->fiz->customers->create(self::CUSTOMER));
        self::assertSame([2000.0], $mock->sleeps);
        self::assertSame($mock->requests[0]['headers']['idempotency-key'], $mock->requests[1]['headers']['idempotency-key']);
    }

    public function testHonoursARetryAfterGivenAsAnHttpDate(): void
    {
        $mock = new MockFiz([
            ['status' => 429, 'body' => [], 'headers' => ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30)]],
            ['status' => 200, 'body' => []],
        ]);
        $mock->fiz->company->retrieve();
        self::assertCount(1, $mock->sleeps);
        self::assertGreaterThan(28_000, $mock->sleeps[0]);
        self::assertLessThanOrEqual(30_000, $mock->sleeps[0]);
    }

    public function testDoesNotWaitOutARetryAfterLongerThanAMinute(): void
    {
        $mock = new MockFiz([
            ['status' => 429, 'body' => ['statusCode' => 429, 'message' => 'slow down'], 'headers' => ['retry-after' => '3600']],
        ]);
        self::assertInstanceOf(FizRateLimitException::class, self::thrown(fn () => $mock->fiz->company->retrieve()));
        self::assertCount(1, $mock->requests);
        self::assertSame([], $mock->sleeps);
    }

    public function testReportsATimeoutAfterRetryingIt(): void
    {
        $timeout = self::noResponse('cURL error 28: Operation timed out after 60001 milliseconds', 28);
        $mock = new MockFiz([$timeout, $timeout, $timeout]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER, ['idempotencyKey' => 'k1']));
        self::assertInstanceOf(FizTimeoutException::class, $error);
        self::assertSame('k1', $error->idempotencyKey);
        self::assertCount(3, $mock->requests);
        self::assertCount(2, $mock->sleeps);
    }

    public function testThrowsTheConflictWhenAWriteIsStillInProgressAfterEveryRetry(): void
    {
        $busy = ['status' => 409, 'body' => ['statusCode' => 409, 'message' => 'still being processed'], 'headers' => ['retry-after' => '1']];
        $mock = new MockFiz([$busy, $busy, $busy]);
        self::assertInstanceOf(FizConflictException::class, self::thrown(fn () => $mock->fiz->invoices->issue('inv1')));
        self::assertCount(3, $mock->requests);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $limited = ['status' => 429, 'body' => ['statusCode' => 429, 'message' => 'slow down'], 'headers' => ['retry-after' => '0']];
        $mock = new MockFiz([$limited, $limited, $limited]);
        self::assertInstanceOf(FizRateLimitException::class, self::thrown(fn () => $mock->fiz->company->retrieve()));
        self::assertCount(3, $mock->requests);
    }

    public function testTakesMaxRetriesPerCall(): void
    {
        $limited = ['status' => 429, 'body' => [], 'headers' => ['retry-after' => '0']];
        $mock = new MockFiz([$limited, $limited]);
        self::thrown(fn () => $mock->fiz->company->retrieve(['maxRetries' => 1]));
        self::assertCount(2, $mock->requests);
    }

    public function testBacksOffExponentiallyWithJitter(): void
    {
        $failed = self::noResponse();
        $mock = new MockFiz([$failed, $failed, $failed, $failed], ['maxRetries' => 3]);
        self::thrown(fn () => $mock->fiz->company->retrieve());
        self::assertCount(3, $mock->sleeps);
        foreach ([500, 1000, 2000] as $i => $base) {
            self::assertGreaterThanOrEqual($base * 0.75, $mock->sleeps[$i]);
            self::assertLessThanOrEqual($base * 1.25, $mock->sleeps[$i]);
        }
    }

    public function testRepeatsAWriteThatGotNoResponseWithTheSameKey(): void
    {
        $mock = new MockFiz([self::noResponse(), ['status' => 201, 'body' => ['id' => 'c1']]]);
        self::assertSame(['id' => 'c1'], $mock->fiz->customers->create(self::CUSTOMER));
        self::assertCount(2, $mock->requests);
        self::assertSame($mock->requests[0]['headers']['idempotency-key'], $mock->requests[1]['headers']['idempotency-key']);
    }

    public function testWaitsForAWriteStillInProgress(): void
    {
        $mock = new MockFiz([
            ['status' => 409, 'body' => ['statusCode' => 409, 'message' => 'still being processed'], 'headers' => ['retry-after' => '1']],
            ['status' => 200, 'body' => ['id' => 'inv1', 'number' => 'FT 2026/1'], 'headers' => ['idempotent-replayed' => 'true']],
        ]);
        $issued = $mock->fiz->invoices->issue('inv1', ['expectedUpdatedAt' => '2026-11-24T10:00:00.000Z']);
        self::assertSame('FT 2026/1', $issued['number']);
        self::assertCount(2, $mock->requests);
        self::assertSame([1000.0], $mock->sleeps);
    }

    public function testRepeatsAWriteWhoseResponseBodyWasCutOffWithTheSameKey(): void
    {
        foreach ([self::cutOff(201), self::cutOffByGuzzle(201)] as $cutOff) {
            $mock = new MockFiz([$cutOff, ['status' => 201, 'body' => ['id' => 'c1']]]);
            self::assertSame(['id' => 'c1'], $mock->fiz->customers->create(self::CUSTOMER, ['idempotencyKey' => 'order-9']));
            self::assertSame(['order-9', 'order-9'], array_map(fn ($r) => $r['headers']['idempotency-key'], $mock->requests));
        }
    }

    public function testRepeatsAnIssueWhoseJsonBodyArrivedBrokenWithTheSameKey(): void
    {
        $mock = new MockFiz([self::text(200, '{"number":'), ['status' => 200, 'body' => ['id' => 'inv1', 'number' => 'FT 2026/1']]]);
        $issued = $mock->fiz->invoices->issue('inv1', [], ['idempotencyKey' => 'issue-1']);
        self::assertSame('FT 2026/1', $issued['number']);
        self::assertSame(['issue-1', 'issue-1'], array_map(fn ($r) => $r['headers']['idempotency-key'], $mock->requests));
    }

    /** @return array<string, array{string}> */
    public static function brokenBodies(): array
    {
        return [
            'broken JSON' => ['{"number":'],
            'an empty body announced as JSON' => [''],
        ];
    }

    #[DataProvider('brokenBodies')]
    public function testNeverReturnsASuccessWithABrokenBodyAndRaisesWithTheKey(string $body): void
    {
        $mock = new MockFiz([self::text(200, $body)], ['maxRetries' => 0]);
        $error = self::thrown(fn () => $mock->fiz->invoices->issue('inv1', [], ['idempotencyKey' => 'issue-1']));
        self::assertInstanceOf(FizConnectionException::class, $error);
        self::assertSame('issue-1', $error->idempotencyKey);
    }

    public function testParsesASuccessAsJsonWhateverTheContentTypeSpelling(): void
    {
        $mock = new MockFiz([self::text(200, '{"id":"inv1","number":"FT 2026/1"}', 'Application/JSON; charset=UTF-8')]);
        self::assertSame('FT 2026/1', $mock->fiz->invoices->issue('inv1')['number']);
    }

    public function testNeverReturnsAnHtmlPageAsASuccess(): void
    {
        $mock = new MockFiz([self::text(200, '<html>maintenance</html>', 'text/html')], ['maxRetries' => 0]);
        $error = self::thrown(fn () => $mock->fiz->invoices->issue('inv1', [], ['idempotencyKey' => 'issue-1']));
        self::assertInstanceOf(FizConnectionException::class, $error);
        self::assertSame('issue-1', $error->idempotencyKey);
    }

    public function testKeepsABrokenErrorBodyAsTextAndLetsTheStatusDecide(): void
    {
        $mock = new MockFiz([self::text(400, 'upstream <html> error')]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER));
        self::assertInstanceOf(FizBadRequestException::class, $error);
        self::assertSame(['upstream <html> error'], $error->messages);
        self::assertCount(1, $mock->requests);
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function cutOffErrors(): array
    {
        return [
            'a 503 on a write' => [503, []],
            'a 429 with a Retry-After longer than a minute' => [429, ['retry-after' => '120']],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('cutOffErrors')]
    public function testKeepsTheStatusRulesWhenTheBodyOfAnErrorIsCutOff(int $status, array $headers): void
    {
        foreach ([self::cutOff($status, $headers), self::cutOffByGuzzle($status, $headers)] as $cutOff) {
            $mock = new MockFiz([$cutOff, ['status' => 201, 'body' => ['id' => 'c1']]]);
            $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER));
            self::assertInstanceOf(FizApiException::class, $error);
            self::assertSame($status, $error->status);
            self::assertCount(1, $mock->requests);
        }
    }

    public function testWaitsOutTheRetryAfterOfA429WhoseBodyIsCutOff(): void
    {
        $mock = new MockFiz([self::cutOff(429, ['retry-after' => '2']), ['status' => 201, 'body' => ['id' => 'c1']]]);
        self::assertSame(['id' => 'c1'], $mock->fiz->customers->create(self::CUSTOMER));
        self::assertSame([2000.0], $mock->sleeps);
    }

    public function testReportsACutOffBodyWithTheKeyWhenItCannotRetry(): void
    {
        $mock = new MockFiz([self::cutOff(201)], ['maxRetries' => 0]);
        $error = self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER, ['idempotencyKey' => 'order-9']));
        self::assertInstanceOf(FizConnectionException::class, $error);
        self::assertSame('order-9', $error->idempotencyKey);
    }

    public function testDoesNotRetryA409WhoseOutcomeIsUnknown(): void
    {
        $mock = new MockFiz([['status' => 409, 'body' => ['statusCode' => 409, 'message' => 'may have taken effect']]]);
        self::assertInstanceOf(FizConflictException::class, self::thrown(fn () => $mock->fiz->invoices->issue('inv1')));
        self::assertCount(1, $mock->requests);
    }

    public function testRetriesA503OnAReadButNotOnAWrite(): void
    {
        $unavailable = ['status' => 503, 'body' => ['statusCode' => 503, 'message' => 'unavailable']];
        $mock = new MockFiz([$unavailable, ['status' => 200, 'body' => []], $unavailable]);
        self::assertSame([], $mock->fiz->company->retrieve());
        self::assertInstanceOf(FizServerException::class, self::thrown(fn () => $mock->fiz->customers->create(self::CUSTOMER)));
        self::assertCount(3, $mock->requests);
    }

    public function testDoesNotRepeatANonIdempotentPostThatGotNoResponse(): void
    {
        $mock = new MockFiz([self::noResponse()]);
        $error = self::thrown(fn () => $mock->fiz->vat->calculate(['itemId' => 'i1']));
        self::assertInstanceOf(FizConnectionException::class, $error);
        self::assertNotInstanceOf(FizTimeoutException::class, $error);
        self::assertNull($error->idempotencyKey);
        self::assertCount(1, $mock->requests);
    }

    // --- pagination ----------------------------------------------------------

    public function testWalksThePagesUntilAnEmptyOne(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => [['id' => '1'], ['id' => '2']]],
            ['status' => 200, 'body' => [['id' => '3']]],
            ['status' => 200, 'body' => []],
        ]);
        $ids = [];
        foreach ($mock->fiz->invoices->listAll(['status' => 'ISSUED', 'pageSize' => 2]) as $invoice) {
            $ids[] = $invoice['id'];
        }
        self::assertSame(['1', '2', '3'], $ids);
        self::assertSame(
            [[['0'], ['2']], [['2'], ['2']], [['3'], ['2']]],
            array_map(fn ($r) => [$r['query']['offset'], $r['query']['limit']], $mock->requests),
        );
        foreach ($mock->requests as $request) {
            self::assertSame(['ISSUED'], $request['query']['status']);
            self::assertArrayNotHasKey('pageSize', $request['query']);
        }
    }

    public function testFetchesNothingUntilIteratedAndPagesBy100ByDefault(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => []]]);
        $all = $mock->fiz->customers->listAll();
        self::assertCount(0, $mock->requests);
        self::assertSame([], iterator_to_array($all));
        self::assertSame(['100'], $mock->requests[0]['query']['limit']);
    }

    public function testWalksNumberedPagesForBankTransactions(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => [['id' => 't1']]],
            ['status' => 200, 'body' => []],
        ]);
        $all = iterator_to_array($mock->fiz->bank->transactionsAll(['connectionId' => 'c1', 'pageSize' => 1]));
        self::assertCount(1, $all);
        self::assertSame([['1'], ['2']], array_map(fn ($r) => $r['query']['page'], $mock->requests));
        self::assertSame(['1'], $mock->requests[0]['query']['pageSize']);
    }

    // --- onResponse ----------------------------------------------------------

    public function testReportsEveryResponseRetriesIncluded(): void
    {
        $seen = [];
        $mock = new MockFiz([
            ['status' => 429, 'body' => ['statusCode' => 429, 'message' => 'slow'], 'headers' => ['retry-after' => '1', 'x-request-id' => 'r1']],
            ['status' => 200, 'body' => ['id' => 'inv1'], 'headers' => ['x-request-id' => 'r2', 'idempotent-replayed' => 'true']],
        ], ['onResponse' => function (array $info) use (&$seen): void {
            $seen[] = $info;
        }]);
        $mock->fiz->invoices->issue('inv1', [], ['idempotencyKey' => 'issue-1']);
        self::assertCount(2, $seen);
        self::assertSame([
            'operationId' => 'InvoicesController_issue',
            'method' => 'POST',
            'path' => '/invoices/{id}/issue',
            'status' => 429,
            'requestId' => 'r1',
            'idempotencyKey' => 'issue-1',
            'replayed' => false,
            'attempt' => 1,
        ], array_diff_key($seen[0], ['durationMs' => true]));
        self::assertIsInt($seen[0]['durationMs']);
        self::assertSame(
            ['status' => 200, 'requestId' => 'r2', 'replayed' => true, 'attempt' => 2],
            array_intersect_key($seen[1], ['status' => true, 'requestId' => true, 'replayed' => true, 'attempt' => true]),
        );
    }

    public function testNeverFailsTheCallWhenTheHookThrows(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => ['id' => 'inv1']]], [
            'onResponse' => function (): void {
                throw new \RuntimeException('logger down');
            },
        ]);
        self::assertSame(['id' => 'inv1'], $mock->fiz->invoices->issue('inv1'));
    }

    public function testIsNotCalledForTheFileDownload(): void
    {
        $seen = [];
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://api.fiz.co/files/FT.pdf']],
            self::text(200, '%PDF-', 'application/pdf'),
        ], ['onResponse' => function (array $info) use (&$seen): void {
            $seen[] = $info['operationId'];
        }]);
        $mock->fiz->invoices->downloadPdf('inv1');
        self::assertSame(['InvoicesController_getPdf'], $seen);
    }

    // --- redirects -----------------------------------------------------------

    public function testRedirectsAreNeverFollowedOnAnApiCall(): void
    {
        $mock = new MockFiz([['status' => 302, 'headers' => ['location' => 'https://elsewhere.example.com/company']]]);
        $error = self::thrown(fn () => $mock->fiz->company->retrieve());
        self::assertInstanceOf(FizException::class, $error);
        self::assertMatchesRegularExpression('/redirect to https:\/\/elsewhere/', $error->getMessage());
        self::assertCount(1, $mock->requests);
        self::assertFalse($mock->requests[0]['options']['allow_redirects']);
    }

    // --- PDF download --------------------------------------------------------

    public function testSendsCredentialsToALinkOnTheApiOriginOnly(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://api.fiz.co/files/FT.pdf']],
            self::text(200, '%PDF-1.7', 'application/pdf'),
        ]);
        self::assertSame('%PDF-1.7', $mock->fiz->invoices->downloadPdf('inv1'));
        self::assertSame('fiz_live_key', $mock->requests[1]['headers']['x-api-key']);
    }

    public function testDropsTheCredentialsWhenARedirectLeavesTheApiOrigin(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://api.fiz.co/files/FT.pdf']],
            ['status' => 302, 'headers' => ['location' => 'https://storage.example.com/FT.pdf?sig=1']],
            self::text(200, '%PDF-1.7', 'application/pdf'),
        ]);
        $mock->fiz->invoices->downloadPdf('inv1');
        self::assertSame([
            ['api.fiz.co', 'fiz_live_key', false],
            ['api.fiz.co', 'fiz_live_key', false],
            ['storage.example.com', null, false],
        ], array_map(fn ($r) => [$r['host'], $r['headers']['x-api-key'] ?? null, $r['options']['allow_redirects']], $mock->requests));
        self::assertSame('https://storage.example.com/FT.pdf?sig=1', $mock->requests[2]['url']);
    }

    public function testStopsAfterFiveRedirects(): void
    {
        $hop = ['status' => 302, 'headers' => ['location' => 'https://storage.example.com/next']];
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://storage.example.com/FT.pdf']],
            $hop, $hop, $hop, $hop, $hop, $hop,
        ]);
        $error = self::thrown(fn () => $mock->fiz->invoices->downloadPdf('inv1'));
        self::assertMatchesRegularExpression('/more than 5 redirects/', $error->getMessage());
        self::assertCount(7, $mock->requests);
    }

    public function testRaisesAFizExceptionWhenTheApiGivesNoLink(): void
    {
        $mock = new MockFiz([['status' => 200, 'body' => ['id' => 'f1', 'name' => null, 'url' => null]]]);
        self::assertInstanceOf(FizException::class, self::thrown(fn () => $mock->fiz->invoices->downloadPdf('inv1')));
    }

    public function testFetchesAForeignLinkWithoutCredentials(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://files.example.com/FT.pdf?sig=1']],
            self::text(200, '%PDF-1.7', 'application/pdf'),
        ]);
        self::assertGreaterThan(0, strlen($mock->fiz->invoices->downloadPdf('inv1')));
        self::assertSame('files.example.com', $mock->requests[1]['host']);
        self::assertArrayNotHasKey('x-api-key', $mock->requests[1]['headers']);
    }

    public function testNeverSendsAForeignLinkThroughTheCallersClient(): void
    {
        // An integration may put its credential anywhere on its Guzzle client:
        // default headers, auth, cookies, query, or a middleware that adds it
        // to every request. None of it may reach the host that stores the
        // file, so that request never goes through the caller's client.
        $hooked = [];
        $api = function (RequestInterface $request) {
            self::assertSame('api.fiz.co', $request->getUri()->getHost(), 'a foreign link reached httpClient');
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['content-type' => 'application/json'], json_encode(
                ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://files.example.com/FT.pdf'],
                JSON_THROW_ON_ERROR,
            )));
        };
        $stack = \GuzzleHttp\HandlerStack::create($api);
        $stack->push(\GuzzleHttp\Middleware::mapRequest(function (RequestInterface $request) use (&$hooked) {
            $hooked[] = $request->getUri()->getHost();
            return $request->withHeader('Authorization', 'Bearer from-a-middleware');
        }));
        $client = new \GuzzleHttp\Client([
            'handler' => $stack,
            'headers' => ['Authorization' => 'Bearer client-default', 'x-api-key' => 'client-default'],
            'auth' => ['user', 'secret'],
            'cookies' => \GuzzleHttp\Cookie\CookieJar::fromArray(['session' => 'client-cookie'], 'files.example.com'),
            'query' => ['token' => 'client-default'],
        ]);
        $files = [];
        $storage = function (RequestInterface $request) use (&$files) {
            $files[] = $request;
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200, ['content-type' => 'application/pdf'], '%PDF'));
        };
        $fiz = new Fiz(['apiKey' => 'fiz_live_key', 'httpClient' => $client]);
        MockFiz::replaceFileClient($fiz, new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($storage)]));
        self::assertSame('%PDF', $fiz->invoices->downloadPdf('inv1'));
        self::assertSame(['api.fiz.co'], $hooked);
        self::assertCount(1, $files);
        self::assertSame('files.example.com', $files[0]->getUri()->getHost());
        foreach (['Authorization', 'x-api-key', 'Cookie'] as $header) {
            self::assertFalse($files[0]->hasHeader($header), "$header was sent");
        }
        self::assertSame('', $files[0]->getUri()->getQuery());
    }

    public function testReportsAFailedDownloadAsAFizException(): void
    {
        $mock = new MockFiz([
            ['status' => 200, 'body' => ['id' => 'f1', 'name' => 'FT.pdf', 'url' => 'https://files.example.com/FT.pdf']],
            self::noResponse(),
        ]);
        self::assertInstanceOf(FizConnectionException::class, self::thrown(fn () => $mock->fiz->invoices->downloadPdf('inv1')));
    }

    // --- any PSR-18 client ---------------------------------------------------

    public function testWorksWithAnyPsr18Client(): void
    {
        $client = new class () implements ClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];
            public int $failures = 1;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if ($this->failures-- > 0) {
                    throw new class ('connection reset', $request) extends \RuntimeException implements NetworkExceptionInterface {
                        public function __construct(string $message, private readonly RequestInterface $request)
                        {
                            parent::__construct($message);
                        }

                        public function getRequest(): RequestInterface
                        {
                            return $this->request;
                        }
                    };
                }
                return new Response(201, ['content-type' => 'application/json'], '{"id":"c1"}');
            }
        };
        $fiz = new Fiz(['apiKey' => 'fiz_live_key', 'httpClient' => $client]);
        MockFiz::replaceSleep($fiz, static function (): void {
        });
        self::assertSame(['id' => 'c1'], $fiz->customers->create(self::CUSTOMER, ['idempotencyKey' => 'order-1']));
        self::assertCount(2, $client->requests);
        self::assertSame('order-1', $client->requests[1]->getHeaderLine('Idempotency-Key'));
        self::assertSame('fiz_live_key', $client->requests[1]->getHeaderLine('x-api-key'));
    }
}
