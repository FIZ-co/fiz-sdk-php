<?php

// Downloads the canonical (English) OpenAPI document into openapi/openapi.json.
// FIZ_SPEC_URL overrides the source, e.g. an API running locally.
// `composer update-spec` runs this, then scripts/generate.php.

declare(strict_types=1);

$url = getenv('FIZ_SPEC_URL') ?: 'https://api.fiz.co/-json';
$context = stream_context_create([
    'http' => [
        'header' => "Accept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 60,
    ],
]);
$text = file_get_contents($url, false, $context);
// PHP 8.4 added the function and deprecated the variable.
$headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
$status = isset($headers[0]) && preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0], $m) ? (int) $m[1] : 0;
if ($text === false || $status !== 200) {
    fwrite(STDERR, "GET {$url} answered " . ($status ?: 'nothing') . "\n");
    exit(1);
}

// Objects stay objects: decoding to arrays would turn an empty `{}` into `[]`.
$spec = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
if (!is_object($spec) || !isset($spec->openapi, $spec->paths)) {
    fwrite(STDERR, "{$url} is not an OpenAPI document\n");
    exit(1);
}

$json = json_encode(
    $spec,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR,
);
// Two-space indentation, as the API document is diffed in pull requests.
$json = preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
file_put_contents(dirname(__DIR__) . '/openapi/openapi.json', $json . "\n");
echo "Saved {$url} (" . count(get_object_vars($spec->paths)) . " paths)\n";
