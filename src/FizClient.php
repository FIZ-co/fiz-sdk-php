<?php

declare(strict_types=1);

namespace Fiz;

use Fiz\Exception\FizApiException;
use Fiz\Exception\FizConnectionException;
use Fiz\Exception\FizException;
use Fiz\Exception\FizTimeoutException;
use Fiz\Generated\Operations;
use Fiz\Generated\Types;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;

/**
 * Low-level HTTP client: auth, Idempotency-Key, retries and error mapping.
 * Each resource method is a typed wrapper around `call()`.
 *
 * @phpstan-import-type ResponseMap from Types
 *
 * @phpstan-type FizOptions array{
 *     apiKey?: string|null,
 *     accessToken?: string|null,
 *     baseUrl?: string,
 *     maxRetries?: int,
 *     timeout?: float|int,
 *     httpClient?: ClientInterface,
 *     requestFactory?: RequestFactoryInterface,
 *     streamFactory?: StreamFactoryInterface,
 *     appInfo?: array{name: string, version?: string},
 *     onResponse?: callable(ResponseInfo): mixed,
 * }
 *
 * @phpstan-type RequestOptions array{
 *     idempotencyKey?: string,
 *     maxRetries?: int,
 *     timeout?: float|int,
 *     headers?: array<string, string>,
 * }
 *
 * @phpstan-type ResponseInfo array{
 *     operationId: string,
 *     method: string,
 *     path: string,
 *     status: int,
 *     requestId: string|null,
 *     idempotencyKey: string|null,
 *     replayed: bool,
 *     attempt: int,
 *     durationMs: int,
 * }
 *
 * @phpstan-type CallArgs array{
 *     path?: array<string, scalar|null>,
 *     query?: array<string, mixed>,
 *     body?: mixed,
 * }
 */
final class FizClient
{
    public const DEFAULT_BASE_URL = 'https://api.fiz.co';

    private const SANDBOX_KEY_PREFIX = 'fiz_test_';
    /** A longer Retry-After is not waited for: the exception is thrown instead. */
    private const MAX_RETRY_AFTER_MS = 60_000;
    private const MAX_REDIRECTS = 5;
    /** What the API accepts as an Idempotency-Key: 1–128 visible ASCII characters. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7e]{1,128}$/D';
    private const OPTIONS = [
        'apiKey', 'accessToken', 'baseUrl', 'maxRetries', 'timeout', 'httpClient',
        'requestFactory', 'streamFactory', 'appInfo', 'onResponse',
    ];
    private const REQUEST_OPTIONS = ['idempotencyKey', 'maxRetries', 'timeout', 'headers'];

    public readonly string $baseUrl;
    public readonly int $maxRetries;
    /** Timeout per attempt, in seconds. */
    public readonly float $timeout;
    /** `sandbox` for a `fiz_test_` key; OAuth tokens and other keys reach production. */
    public readonly string $environment;

    /** @var array<string, string> */
    private readonly array $authHeaders;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly string $userAgent;
    /** @var (callable(ResponseInfo): mixed)|null */
    private $onResponse;
    /** Waits between attempts; replaced in the unit tests. */
    private \Closure $sleep;

    /**
     * @param FizOptions $options
     */
    public function __construct(array $options = [])
    {
        self::refuseUnknown($options, self::OPTIONS, 'option');
        $apiKey = $options['apiKey'] ?? null;
        $accessToken = ($options['accessToken'] ?? '') !== '' ? $options['accessToken'] : null;
        if ($apiKey !== null && $apiKey !== '' && $accessToken !== null) {
            throw new FizException('Pass either apiKey or accessToken, not both.');
        }
        if ($accessToken === null) {
            // FIZ_API_KEY when no key is given; an empty key is refused below.
            $apiKey ??= self::envApiKey();
            if ($apiKey === null || $apiKey === '') {
                throw new FizException(
                    'No credential: pass apiKey (or set FIZ_API_KEY) or accessToken. API keys are created at https://app.fiz.co/settings/integrations.',
                );
            }
            $this->authHeaders = ['x-api-key' => $apiKey];
        } else {
            $this->authHeaders = ['authorization' => 'Bearer ' . $accessToken];
        }
        $this->environment = $apiKey !== null && str_starts_with($apiKey, self::SANDBOX_KEY_PREFIX) ? 'sandbox' : 'production';
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $this->maxRetries = $options['maxRetries'] ?? 2;
        $this->timeout = (float) ($options['timeout'] ?? 60);
        $this->httpClient = $options['httpClient'] ?? new GuzzleClient();
        $factory = new HttpFactory();
        $this->requestFactory = $options['requestFactory'] ?? $factory;
        $this->streamFactory = $options['streamFactory'] ?? $factory;
        $this->userAgent = self::userAgent($options['appInfo'] ?? null);
        $this->onResponse = $options['onResponse'] ?? null;
        $this->sleep = static function (float $ms): void {
            usleep((int) round($ms * 1000));
        };
    }

    /**
     * Calls any API operation by its OpenAPI `operationId`. Resource methods
     * use this; call it directly for an operation this SDK version does not
     * wrap yet.
     *
     * @template K of key-of<ResponseMap>
     * @param K $operationId
     * @param CallArgs $args
     * @param RequestOptions $options
     * @return ResponseMap[K]
     */
    public function call(string $operationId, array $args = [], array $options = []): mixed
    {
        $operation = self::operation($operationId);
        if ($operation === null) {
            throw new FizException("Unknown operation $operationId");
        }
        self::refuseUnknown($args, ['path', 'query', 'body'], 'argument');
        self::refuseUnknown($options, self::REQUEST_OPTIONS, 'request option');
        $method = $operation['method'];

        $path = self::expandPath($operation['path'], $args['path'] ?? []);
        $url = $this->baseUrl . $path . self::queryString($args['query'] ?? []);
        // Header names are case-insensitive: they are kept lower-case here, so a
        // caller's `Idempotency-Key` replaces ours instead of being sent twice.
        $headers = ['accept' => 'application/json', 'user-agent' => $this->userAgent] + $this->authHeaders;
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        // One key for the whole call, every retry included. A key the caller gave
        // (option or header) is checked here: an empty one would send no header
        // while the retry logic still treated the write as safe to repeat.
        $given = $options['idempotencyKey'] ?? $headers['idempotency-key'] ?? null;
        unset($headers['idempotency-key']);
        $idempotencyKey = $operation['idempotent'] ? self::checkedIdempotencyKey($given) : null;
        if ($idempotencyKey !== null) {
            $headers['idempotency-key'] = $idempotencyKey;
        }
        $body = null;
        if (($args['body'] ?? null) !== null) {
            $headers['content-type'] = 'application/json';
            $body = self::encodeBody($args['body']);
        }

        $maxRetries = $options['maxRetries'] ?? $this->maxRetries;
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        // Safe to repeat without knowing whether the first attempt arrived: a read,
        // or a write carrying an Idempotency-Key (the API replays the first result).
        $repeatable = $method === 'GET' || $idempotencyKey !== null;

        for ($attempt = 0;; $attempt++) {
            $canRetry = $attempt < $maxRetries;
            // Two ways an attempt can go without a usable answer, decided apart:
            //  - no status at all (the request may or may not have arrived): repeat
            //    only what is safe to repeat blind;
            //  - a status whose body was cut off: the status still decides, exactly
            //    as with a body (a 503 or 429 keeps its own rules and Retry-After).
            //    Only a success whose body is lost is a lost response, which the same
            //    Idempotency-Key recovers.
            $bodyLost = null;
            $payload = null;
            $sentAt = hrtime(true);
            try {
                $response = $this->attempt($method, $url, $headers, $body, $timeout);
            } catch (ClientExceptionInterface $error) {
                $response = self::partialResponse($error);
                if ($response === null) {
                    if (!$repeatable || !$canRetry) {
                        throw self::noResponseError($error, "$method $path", $timeout, $idempotencyKey);
                    }
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                $bodyLost = $error;
            }
            $status = $response->getStatusCode();
            $this->report([
                'operationId' => $operationId,
                'method' => $method,
                'path' => $operation['path'],
                'status' => $status,
                'requestId' => self::header($response, 'x-request-id'),
                'idempotencyKey' => $idempotencyKey,
                'replayed' => self::header($response, 'idempotent-replayed') === 'true',
                'attempt' => $attempt + 1,
                'durationMs' => intdiv(hrtime(true) - $sentAt, 1_000_000),
            ]);
            $ok = $status >= 200 && $status < 300;
            if ($bodyLost === null) {
                try {
                    $text = (string) $response->getBody();
                    // Every operation answers a success in JSON (tests/Unit/CoverageTest
                    // holds the API document to that), so a success is parsed as JSON
                    // whatever Content-Type says, and one that does not parse is a lost
                    // response: issue() must not "succeed" with half an invoice or an
                    // HTML page. An error body is read loosely; its status decides.
                    $payload = $ok ? json_decode($text, true, 512, JSON_THROW_ON_ERROR) : self::parseErrorBody($text);
                } catch (\JsonException|\RuntimeException $error) {
                    $bodyLost = $error;
                }
            }

            if ($ok) {
                if ($bodyLost === null) {
                    /** @var ResponseMap[K] $payload */
                    return $payload;
                }
                if (!$repeatable || !$canRetry) {
                    throw self::noResponseError($bodyLost, "$method $path", $timeout, $idempotencyKey);
                }
                ($this->sleep)(self::backoff($attempt));
                continue;
            }
            if ($status >= 300 && $status < 400) {
                // Not followed: the credentials go to the API's origin only.
                $location = self::header($response, 'location');
                throw new FizException(
                    "$method $path answered with a redirect" . ($location !== null ? " to $location" : '') . '; it was not followed.',
                );
            }

            $error = FizApiException::from(
                $status,
                $payload,
                self::headers($response),
                $method,
                $path,
                $idempotencyKey,
            );
            $retryAfter = self::retryAfterMs($response);
            if (
                !$canRetry
                // Waiting less than the server asked would only earn another refusal.
                || ($retryAfter !== null && $retryAfter > self::MAX_RETRY_AFTER_MS)
                || !self::shouldRetry($status, $method, $idempotencyKey, $retryAfter)
            ) {
                throw $error;
            }
            ($this->sleep)($retryAfter ?? self::backoff($attempt));
        }
    }

    /**
     * Downloads a file the API linked to (a PDF, an export) and returns its
     * bytes. Credentials are sent only to the API's own origin, checked again
     * on every redirect, and at most 5 redirects are followed.
     *
     * @param array{timeout?: float|int} $options
     */
    public function download(string $fileUrl, array $options = []): string
    {
        self::refuseUnknown($options, ['timeout'], 'download option');
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $apiOrigin = self::origin(new Uri($this->baseUrl));
        $start = UriResolver::resolve(new Uri($this->baseUrl), new Uri($fileUrl));
        $uri = $start;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $own = self::origin($uri) === $apiOrigin;
            $headers = ['user-agent' => $this->userAgent] + ($own ? $this->authHeaders : []);
            try {
                $response = $this->attempt('GET', (string) $uri, $headers, null, $timeout, bare: !$own);
            } catch (ClientExceptionInterface $error) {
                throw self::noResponseError($error, "Downloading {$uri->getPath()}", $timeout, null);
            }
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $next = self::header($response, 'location');
                if ($next === null) {
                    throw new FizException("Downloading {$uri->getPath()}: a redirect without a Location header");
                }
                $uri = UriResolver::resolve($uri, new Uri($next));
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new FizException("Downloading {$uri->getPath()} failed with $status");
            }
            try {
                return (string) $response->getBody();
            } catch (\RuntimeException $error) {
                throw self::noResponseError($error, "Downloading {$uri->getPath()}", $timeout, null);
            }
        }
        throw new FizException("Downloading {$start->getPath()}: more than " . self::MAX_REDIRECTS . ' redirects');
    }

    /**
     * The route of an operation; null for an id the table does not have (a
     * caller without static analysis can pass any string).
     *
     * @return array{method: string, path: string, idempotent: bool}|null
     */
    private static function operation(string $operationId): ?array
    {
        return Operations::OPERATIONS[$operationId] ?? null;
    }

    /** @param ResponseInfo $info */
    private function report(array $info): void
    {
        if ($this->onResponse === null) {
            return;
        }
        // A logging hook must never fail or retry the call it observes.
        try {
            ($this->onResponse)($info);
        } catch (\Throwable) {
            // ignored, as above
        }
    }

    /**
     * @param array<string, string> $headers
     * @param bool $bare For another origin (where a file is stored): none of
     *        the Guzzle client's own defaults that could carry a credential
     *        (headers, auth, cookies, query) is applied.
     */
    private function attempt(string $method, string $url, array $headers, ?string $body, float $timeout, bool $bare = false): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        if ($this->httpClient instanceof GuzzleClientInterface) {
            return $this->httpClient->send($request, [
                'timeout' => $timeout,
                // Never followed automatically: a redirect keeps custom headers such
                // as x-api-key, whatever origin it points to.
                'allow_redirects' => false,
                'http_errors' => false,
            ] + ($bare ? ['headers' => null, 'auth' => null, 'cookies' => false, 'query' => null] : []));
        }
        // Any other PSR-18 client: its own timeout and redirect settings apply
        // (README, "HTTP client").
        return $this->httpClient->sendRequest($request);
    }

    /**
     * Which failed responses are retried, following the API's idempotency rules:
     *  - 429: refused before the request ran.
     *  - 409 with Retry-After on a keyed write: the first attempt is still
     *    running; the same key returns its result once done. A 409 without
     *    Retry-After means the outcome is unknown and must not be retried blindly.
     *  - 502/503/504 on a read. On a write, a 5xx may already have taken effect
     *    and the key is spent; retrying would only answer 409.
     */
    private static function shouldRetry(int $status, string $method, ?string $idempotencyKey, ?float $retryAfter): bool
    {
        return match (true) {
            $status === 429 => true,
            $status === 409 => $idempotencyKey !== null && $retryAfter !== null,
            in_array($status, [502, 503, 504], true) => $method === 'GET',
            default => false,
        };
    }

    /** @param array<string, scalar|null> $params */
    private static function expandPath(string $template, array $params): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $match) use ($template, $params): string {
            $value = $params[$match[1]] ?? null;
            if ($value === null || $value === '') {
                throw new FizException("Missing path parameter \"{$match[1]}\" for $template");
            }
            return rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }, $template);
    }

    /**
     * Query parameters as the API reads them: a list repeats its key
     * (`types=A&types=B`), null is left out, booleans are `true`/`false` and
     * dates ISO 8601 in UTC.
     *
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                $pairs[] = urlencode((string) $key) . '=' . urlencode(self::queryValue($item));
            }
        }
        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function queryValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new FizException('Query values must be scalars, dates or lists of them, not ' . get_debug_type($value)),
        };
    }

    private static function encodeBody(mixed $body): string
    {
        try {
            // A body is a JSON object: an empty PHP array is `{}`, not `[]`.
            return $body === []
                ? '{}'
                : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $error) {
            throw new FizException('The request body cannot be sent as JSON: ' . $error->getMessage(), 0, $error);
        }
    }

    /** An error response's body: its JSON when it is JSON, else the text, if any. */
    private static function parseErrorBody(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text === '' ? null : $text;
        }
    }

    private static function retryAfterMs(ResponseInterface $response): ?float
    {
        $value = self::header($response, 'retry-after');
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (is_numeric(trim($value))) {
            $ms = (float) trim($value) * 1000;
        } else {
            $time = strtotime($value);
            if ($time === false) {
                return null;
            }
            $ms = ($time - microtime(true)) * 1000;
        }
        return max($ms, 0.0);
    }

    /** 0.5 s, 1 s, 2 s, … capped at 8 s, with ±25 % jitter. */
    private static function backoff(int $attempt): float
    {
        $base = min(500 * 2 ** $attempt, 8_000);
        return $base * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
    }

    private static function envApiKey(): ?string
    {
        foreach ([getenv('FIZ_API_KEY'), $_ENV['FIZ_API_KEY'] ?? null, $_SERVER['FIZ_API_KEY'] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * `fiz-sdk-php/<version> php/<version> <app>/<version>`.
     *
     * @param array{name: string, version?: string}|null $appInfo
     */
    private static function userAgent(?array $appInfo): string
    {
        $parts = ['fiz-sdk-php/' . Fiz::VERSION, 'php/' . PHP_VERSION];
        if ($appInfo !== null) {
            $parts[] = isset($appInfo['version']) && $appInfo['version'] !== ''
                ? "{$appInfo['name']}/{$appInfo['version']}"
                : $appInfo['name'];
        }
        return implode(' ', $parts);
    }

    /** The exception for an attempt that got no complete response: a timeout or a network failure. */
    private static function noResponseError(\Throwable $error, string $request, float $timeout, ?string $idempotencyKey): FizConnectionException
    {
        if (self::isTimeout($error)) {
            return new FizTimeoutException("$request timed out after {$timeout} s", $idempotencyKey, $error);
        }
        return new FizConnectionException(
            "$request failed: no complete response ({$error->getMessage()})",
            $idempotencyKey,
            $error,
        );
    }

    private static function isTimeout(\Throwable $error): bool
    {
        if ($error instanceof ConnectException || $error instanceof RequestException) {
            // CURLE_OPERATION_TIMEDOUT
            if (($error->getHandlerContext()['errno'] ?? null) === 28) {
                return true;
            }
        }
        return preg_match('/timed out|timeout/i', $error->getMessage()) === 1;
    }

    /**
     * The response of a transfer that failed after its headers arrived (Guzzle
     * reports a body cut off mid-way like this): its status is known.
     */
    private static function partialResponse(ClientExceptionInterface $error): ?ResponseInterface
    {
        return $error instanceof RequestException ? $error->getResponse() : null;
    }

    /**
     * The caller's Idempotency-Key, refused before sending when the API would
     * refuse it; a new UUID when there is none.
     */
    private static function checkedIdempotencyKey(?string $given): string
    {
        if ($given === null) {
            return self::uuid();
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $given) !== 1) {
            throw new FizException(
                'idempotencyKey must be 1 to 128 visible ASCII characters (no spaces); omit it to have one generated.',
            );
        }
        return $given;
    }

    /** A random (version 4) UUID. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function origin(UriInterface $uri): string
    {
        $scheme = strtolower($uri->getScheme());
        $port = $uri->getPort() ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
        return $scheme . '://' . strtolower($uri->getHost()) . ':' . $port;
    }

    private static function header(ResponseInterface $response, string $name): ?string
    {
        return $response->hasHeader($name) ? $response->getHeaderLine($name) : null;
    }

    /** @return array<string, list<string>> header names in lower case */
    private static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }
        return $headers;
    }

    /**
     * An option PHP would otherwise ignore silently (`apikey` for `apiKey`)
     * is refused: a typo must not fall back to another credential.
     *
     * @param array<mixed> $given
     * @param list<string> $known
     */
    private static function refuseUnknown(array $given, array $known, string $what): void
    {
        $unknown = array_diff(array_map('strval', array_keys($given)), $known);
        if ($unknown !== []) {
            throw new FizException("Unknown $what: " . implode(', ', $unknown) . '. Known: ' . implode(', ', $known) . '.');
        }
    }
}
