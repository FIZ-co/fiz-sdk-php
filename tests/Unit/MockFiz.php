<?php

declare(strict_types=1);

namespace Fiz\Tests\Unit;

use Fiz\Fiz;
use Fiz\FizClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Fiz client whose Guzzle transport answers from a queue of replies and
 * records every request, and whose waits between attempts are recorded
 * instead of slept.
 *
 * A reply is `['status' => …, 'body' => …, 'headers' => […]]` (the body is
 * sent as JSON), a Throwable to throw, or a callable returning a response.
 */
final class MockFiz
{
    public readonly Fiz $fiz;
    /** @var list<array{method: string, url: string, host: string, path: string, query: array<string, list<string>>, headers: array<string, string>, body: mixed, rawBody: string, options: array<string, mixed>}> */
    public array $requests = [];
    /** @var list<float> milliseconds */
    public array $sleeps = [];

    /**
     * @param list<array{status: int, body?: mixed, headers?: array<string, string>}|\Throwable|callable(RequestInterface, array<string, mixed>): ResponseInterface> $replies
     * @param array<string, mixed> $options
     */
    public function __construct(array $replies, array $options = [])
    {
        $queue = array_map(fn ($reply) => function (RequestInterface $request, array $transferOptions) use ($reply) {
            $this->record($request, $transferOptions);
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            if (is_callable($reply)) {
                return $reply($request, $transferOptions);
            }
            return new Response(
                $reply['status'],
                ['content-type' => 'application/json'] + ($reply['headers'] ?? []),
                array_key_exists('body', $reply) ? json_encode($reply['body'], JSON_THROW_ON_ERROR) : '',
            );
        }, $replies);
        $handler = new MockHandler($queue);
        $this->fiz = new Fiz(array_merge(
            ['apiKey' => 'fiz_live_key', 'httpClient' => new Client(['handler' => HandlerStack::create($handler)])],
            $options,
        ));
        // Files on another origin go through the SDK's own client: answered by
        // the same queue, so a test reads every request in order.
        self::replaceFileClient($this->fiz, new Client(['handler' => HandlerStack::create($handler)]));
        self::replaceSleep($this->fiz, function (float $ms): void {
            $this->sleeps[] = $ms;
        });
    }

    public static function replaceFileClient(Fiz $fiz, Client $fileClient): void
    {
        $client = (new \ReflectionProperty(Fiz::class, 'client'))->getValue($fiz);
        (new \ReflectionProperty(FizClient::class, 'fileClient'))->setValue($client, $fileClient);
    }

    public static function replaceSleep(Fiz $fiz, \Closure $sleep): void
    {
        $client = (new \ReflectionProperty(Fiz::class, 'client'))->getValue($fiz);
        (new \ReflectionProperty(FizClient::class, 'sleep'))->setValue($client, $sleep);
    }

    /** @param array<string, mixed> $options */
    private function record(RequestInterface $request, array $options): void
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $query = [];
        foreach (explode('&', $request->getUri()->getQuery()) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_map('urldecode', explode('=', $pair, 2) + [1 => '']);
            $query[$key][] = $value;
        }
        $body = (string) $request->getBody();
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'host' => $request->getUri()->getHost(),
            'path' => $request->getUri()->getPath(),
            'query' => $query,
            'headers' => $headers,
            'body' => $body === '' ? null : json_decode($body, true),
            'rawBody' => $body,
            'options' => $options,
        ];
    }
}
