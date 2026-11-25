<?php

declare(strict_types=1);

namespace Fiz\Tests\Sandbox;

use Fiz\Generated\Operations;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Checks every response the API really sends against the API document
 * (openapi/openapi.json), with a strict validator: the schema the generated
 * types trust must accept what the API returns, or code written against
 * those types breaks on a real response. Used by the sandbox tests.
 *
 * A failure names the operation, the status and the JSON path that failed,
 * never the value: these are real (sandbox) records, and AGENTS.md keeps
 * account data out of logs that may be published.
 */
final class Contract
{
    private const SPEC_ID = 'file:///openapi.json';

    /** @var list<array{operation: string, status: int, problem: string}> */
    public array $failures = [];

    /** @var array<string, mixed> */
    private array $spec;
    private SchemaStorage $storage;

    public function __construct(private readonly string $apiOrigin)
    {
        $text = (string) file_get_contents(__DIR__ . '/../../openapi/openapi.json');
        $this->spec = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $this->storage = new SchemaStorage();
        $this->storage->addSchema(self::SPEC_ID, self::toJsonSchema(json_decode($text, false, 512, JSON_THROW_ON_ERROR)));
    }

    /**
     * A Guzzle client that validates every response from the API origin and
     * records what does not match. Other hosts (a PDF on storage) pass
     * through unchecked. The SDK still drives it as its default transport.
     */
    public function client(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(fn (callable $handler) => fn (RequestInterface $request, array $options) => $handler($request, $options)
            ->then(function (ResponseInterface $response) use ($request): ResponseInterface {
                $this->check($request, $response);
                return $response;
            }));
        return new Client(['handler' => $stack]);
    }

    private function check(RequestInterface $request, ResponseInterface $response): void
    {
        $uri = $request->getUri();
        $origin = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        $status = $response->getStatusCode();
        // Redirects (3xx) carry no documented body; everything else is checked.
        if ($origin !== $this->apiOrigin || ($status >= 300 && $status < 400)) {
            return;
        }
        $body = $response->getBody();
        $text = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        array_push($this->failures, ...$this->problems($request->getMethod(), $uri->getPath(), $status, $text));
    }

    /** @return list<array{operation: string, status: int, problem: string}> */
    private function problems(string $method, string $path, int $status, string $text): array
    {
        $operation = self::operationOf($method, $path);
        if ($operation === null) {
            return [['operation' => "$method $path", 'status' => $status, 'problem' => 'no operation in the API document']];
        }
        $name = "$method $operation";
        $responses = $this->spec['paths'][$operation][strtolower($method)]['responses'];
        if (!isset($responses[(string) $status])) {
            return [['operation' => $name, 'status' => $status, 'problem' => 'status not documented']];
        }
        if (!isset($responses[(string) $status]['content']['application/json'])) {
            return [];
        }
        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [['operation' => $name, 'status' => $status, 'problem' => 'body is not JSON']];
        }
        $pointer = '#/' . implode('/', array_map(
            static fn (string $part): string => str_replace(['~', '/'], ['~0', '~1'], $part),
            ['paths', $operation, strtolower($method), 'responses', (string) $status, 'content', 'application/json', 'schema'],
        ));
        $validator = new Validator(new Factory($this->storage));
        $validator->validate($data, (object) ['$ref' => self::SPEC_ID . $pointer], Constraint::CHECK_MODE_DISABLE_FORMAT);
        $problems = [];
        foreach ($validator->getErrors() as $error) {
            $problems[] = [
                'operation' => $name,
                'status' => $status,
                'problem' => ($error['pointer'] !== '' ? $error['pointer'] : '(body)') . " {$error['constraint']['name']}: {$error['message']}",
            ];
        }
        return $problems;
    }

    /** The operation an API request belongs to; literal routes win over `{id}`. */
    private static function operationOf(string $method, string $path): ?string
    {
        $candidates = [];
        foreach (Operations::OPERATIONS as $operation) {
            $pattern = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($operation['path'], '#')) . '$#';
            if ($operation['method'] === $method && preg_match($pattern, $path) === 1) {
                $candidates[$operation['path']] = substr_count($operation['path'], '{');
            }
        }
        asort($candidates);
        return array_key_first($candidates);
    }

    /**
     * OpenAPI 3.0 → JSON Schema draft 4, which the validator implements:
     * `nullable` becomes a `null` type (or a `null` alternative where the
     * schema has no type). Formats and the documentation keywords are not
     * checked; boolean exclusiveMinimum/Maximum are already draft 4.
     */
    private static function toJsonSchema(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map([self::class, 'toJsonSchema'], $node);
        }
        if (!$node instanceof \stdClass) {
            return $node;
        }
        $out = new \stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            $out->{$key} = self::toJsonSchema($value);
        }
        if (($out->nullable ?? false) === true) {
            unset($out->nullable);
            if (isset($out->enum) && is_array($out->enum) && !in_array(null, $out->enum, true)) {
                $out->enum[] = null;
            }
            if (isset($out->type)) {
                $out->type = array_values(array_unique([...(array) $out->type, 'null']));
            } elseif (!isset($out->enum)) {
                return (object) ['anyOf' => [$out, (object) ['type' => 'null']]];
            }
        }
        return $out;
    }
}
