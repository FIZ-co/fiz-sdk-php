<?php

declare(strict_types=1);

namespace Fiz\Tests\Unit;

use Fiz\Generated\Operations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../openapi/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string}> */
    public static function operationIds(): array
    {
        $ids = [];
        foreach (array_keys(Operations::OPERATIONS) as $id) {
            $ids[$id] = [$id];
        }
        return $ids;
    }

    /**
     * When `composer update-spec` brings a new operation, this fails until a
     * resource method wraps it (it is already callable through `$fiz->call()`).
     */
    #[DataProvider('operationIds')]
    public function testEveryApiOperationHasAResourceMethod(string $operationId): void
    {
        $source = implode("\n", array_map('file_get_contents', glob(__DIR__ . '/../../src/Resources/*.php') ?: []));
        self::assertStringContainsString("'$operationId'", $source);
    }

    public function testTheGeneratedOperationTableMatchesTheSpec(): void
    {
        $fromSpec = [];
        foreach (self::spec()['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                if (isset($item[$method])) {
                    $fromSpec[$item[$method]['operationId']] = ['method' => strtoupper($method), 'path' => $path];
                }
            }
        }
        ksort($fromSpec);
        $table = array_map(fn (array $op) => ['method' => $op['method'], 'path' => $op['path']], Operations::OPERATIONS);
        ksort($table);
        self::assertSame($fromSpec, $table);
    }

    public function testItMarksTheWritesThatTakeAnIdempotencyKey(): void
    {
        self::assertTrue(Operations::OPERATIONS['InvoicesController_issue']['idempotent']);
        self::assertFalse(Operations::OPERATIONS['InvoicesController_findAll']['idempotent']);
        self::assertFalse(Operations::OPERATIONS['VatController_calculate']['idempotent']);
    }

    /**
     * The client parses a success as JSON and treats anything else as a lost
     * response. An operation answering something else (a file, CSV) needs the
     * client taught first.
     */
    public function testEverySuccessOfEveryOperationIsJsonAsTheClientAssumes(): void
    {
        $notJson = [];
        foreach (self::spec()['paths'] as $path => $item) {
            foreach (self::METHODS as $method) {
                foreach ($item[$method]['responses'] ?? [] as $status => $response) {
                    if (!str_starts_with((string) $status, '2')) {
                        continue;
                    }
                    $types = array_keys($response['content'] ?? []);
                    if ($types === [] || array_diff($types, ['application/json']) !== []) {
                        $notJson[] = strtoupper($method) . " $path $status: " . (implode(', ', $types) ?: 'no body');
                    }
                }
            }
        }
        self::assertSame([], $notJson);
    }
}
