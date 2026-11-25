<?php

// Writes method-map.json: which method of the client wraps each API
// operation, and the arguments it takes, in order. The API docs (api.fiz.co,
// the reference by resource) read it to show each operation in PHP;
// tests/Unit/MethodMapTest.php keeps it equal to the code.
//
//   composer method-map           rewrites method-map.json
//
// Run from the repository root (composer does).

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Fiz\Fiz;
use Fiz\Generated\Operations;
use Fiz\Resources\Resource;

/** @return array{language: string, operations: array<string, array{method: string, params: list<array<string, mixed>>}>} */
function fizMethodMap(): array
{
    $fiz = new Fiz(['apiKey' => 'fiz_test_method_map']);
    $operations = [];
    // Properties and methods in the order they are declared: when two methods
    // wrap one operation, the first one declared is the one to show.
    foreach ((new ReflectionClass($fiz))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $resource = $property->getValue($fiz);
        if (!$resource instanceof Resource) {
            continue;
        }
        $class = new ReflectionClass($resource);
        $lines = file((string) $class->getFileName());
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName() || $method->isConstructor()) {
                continue;
            }
            $source = implode('', array_slice((array) $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            if (preg_match("/->call\\(\\s*'(\\w+)'/", $source, $found) !== 1) {
                continue; // a helper on top of another method (listAll, downloadPdf)
            }
            $operationId = $found[1];
            if (isset($operations[$operationId])) {
                continue;
            }
            // 'path' => ['starterId' => $starterId]: the argument behind each path parameter.
            $pathArgs = [];
            if (preg_match("/'path'\\s*=>\\s*\\[([^\\]]*)\\]/", $source, $path) === 1) {
                preg_match_all("/'(\\w+)'\\s*=>\\s*\\$(\\w+)/", $path[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as [, $param, $arg]) {
                    $pathArgs[$arg] = $param;
                }
            }
            preg_match_all('/\{(\w+)\}/', Operations::OPERATIONS[$operationId]['path'], $expected);
            $declared = array_values($pathArgs);
            sort($declared);
            $wanted = $expected[1];
            sort($wanted);
            if ($declared !== $wanted) {
                throw new RuntimeException("{$property->getName()}->{$method->getName()}: path parameters not all passed");
            }
            $params = [];
            foreach ($method->getParameters() as $parameter) {
                $name = $parameter->getName();
                if ($name === 'options') {
                    continue;
                }
                $where = isset($pathArgs[$name]) ? 'path' : (in_array($name, ['body', 'query'], true) ? $name : null);
                if ($where === null) {
                    throw new RuntimeException("{$property->getName()}->{$method->getName()}: argument \${$name} is neither a path parameter, body nor query");
                }
                $entry = ['name' => $name, 'in' => $where, 'required' => !$parameter->isOptional()];
                if ($where === 'path') {
                    $entry['param'] = $pathArgs[$name];
                }
                $params[] = $entry;
            }
            $operations[$operationId] = ['method' => "{$property->getName()}->{$method->getName()}", 'params' => $params];
        }
    }
    ksort($operations);
    return ['language' => 'php', 'operations' => $operations];
}

function fizMethodMapJson(): string
{
    return json_encode(fizMethodMap(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    file_put_contents(dirname(__DIR__) . '/method-map.json', fizMethodMapJson());
}
