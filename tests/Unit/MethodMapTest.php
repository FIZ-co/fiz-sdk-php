<?php

declare(strict_types=1);

namespace Fiz\Tests\Unit;

use Fiz\Generated\Operations;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/method-map.php';

/**
 * The API docs show each operation in PHP from method-map.json: regenerate
 * it with `composer method-map` after adding or renaming a method.
 */
final class MethodMapTest extends TestCase
{
    public function testMethodMapJsonMatchesTheCode(): void
    {
        self::assertSame(fizMethodMapJson(), (string) file_get_contents(__DIR__ . '/../../method-map.json'));
    }

    public function testEveryOperationHasAMethodInTheMap(): void
    {
        /** @var array{operations: array<string, mixed>} $map */
        $map = json_decode((string) file_get_contents(__DIR__ . '/../../method-map.json'), true, 512, JSON_THROW_ON_ERROR);
        $mapped = array_keys($map['operations']);
        $all = array_keys(Operations::OPERATIONS);
        sort($mapped);
        sort($all);
        self::assertSame($all, $mapped);
    }
}
