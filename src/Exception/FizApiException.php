<?php

declare(strict_types=1);

namespace Fiz\Exception;

/**
 * The API answered with a 4xx or 5xx status. Branch on `status` and
 * `errorCode`; the message is for people and its wording may change.
 *
 * `getCode()` returns the HTTP status too. The API's own `code` is
 * `errorCode`, because PHP reserves `$code` for the exception's integer code.
 */
class FizApiException extends FizException
{
    private const CLASS_BY_STATUS = [
        400 => FizBadRequestException::class,
        401 => FizAuthenticationException::class,
        403 => FizPermissionException::class,
        404 => FizNotFoundException::class,
        409 => FizConflictException::class,
        422 => FizUnprocessableEntityException::class,
        429 => FizRateLimitException::class,
    ];

    /**
     * Every message of the response: one per field when validation fails.
     *
     * @var list<string>
     */
    public readonly array $messages;
    /**
     * Stable, machine-readable reason (the response's `code`), when the
     * invoicing system refused the request (e.g. `ENTITY_NOT_FOUND`). Null on
     * request validation and authentication errors.
     */
    public readonly ?string $errorCode;
    /** `X-Request-Id` of the response. Quote it when contacting FIZ support. */
    public readonly ?string $requestId;

    /**
     * @param int $status HTTP status of the response.
     * @param mixed $body Parsed response body, as received.
     * @param array<string, list<string>> $headers Response headers, names in lower case.
     * @param string|null $idempotencyKey The Idempotency-Key the request carried, if it
     *     was a write. After a 409 whose outcome is unknown, check whether the write
     *     took effect before retrying with a NEW key.
     */
    final public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers,
        string $method,
        string $path,
        public readonly ?string $idempotencyKey,
    ) {
        $this->messages = self::messagesOf($body);
        $this->errorCode = self::codeOf($body);
        $this->requestId = isset($headers['x-request-id'][0]) ? $headers['x-request-id'][0] : null;
        parent::__construct(
            "$method $path failed with $status: " . ($this->messages === [] ? 'no message' : implode('; ', $this->messages)),
            $status,
        );
    }

    /**
     * Builds the subclass that matches the status.
     *
     * @param array<string, list<string>> $headers
     */
    public static function from(
        int $status,
        mixed $body,
        array $headers,
        string $method,
        string $path,
        ?string $idempotencyKey,
    ): self {
        $class = self::CLASS_BY_STATUS[$status] ?? ($status >= 500 ? FizServerException::class : self::class);
        return new $class($status, $body, $headers, $method, $path, $idempotencyKey);
    }

    /** @return list<string> */
    private static function messagesOf(mixed $body): array
    {
        if (is_string($body)) {
            return $body === '' ? [] : [$body];
        }
        $message = is_array($body) ? ($body['message'] ?? null) : null;
        if (is_string($message)) {
            return [$message];
        }
        if (is_array($message)) {
            return array_values(array_filter($message, 'is_string'));
        }
        return [];
    }

    private static function codeOf(mixed $body): ?string
    {
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        return is_string($code) && $code !== '' ? $code : null;
    }
}
