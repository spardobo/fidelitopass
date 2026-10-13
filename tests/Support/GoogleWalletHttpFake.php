<?php

namespace Tests\Support;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Routes test-only PSR requests through Guzzle's mock and history middleware.
 */
final class GoogleWalletHttpFake
{
    public Client $client;

    /** @var array<int, array<string, mixed>> Native Guzzle history, including transport options and failures. */
    public array $history = [];

    /** @var array<string, mixed>|Closure Response fixtures or a request callback. */
    private array|Closure $responses = [];

    /**
     * Creates an isolated transport that rejects every unconfigured request.
     */
    public function __construct()
    {
        $handler = function (RequestInterface $request, array $options): PromiseInterface {
            $recorded = new Request($request);
            $response = null;

            if ($this->responses instanceof Closure) {
                $response = ($this->responses)($recorded);
            } else {
                foreach ($this->responses as $url => $fixture) {
                    if (Str::is($url, (string) $request->getUri())) {
                        $response = $fixture;
                        break;
                    }
                }
            }

            if ($response instanceof MockHandler) {
                return $response($request, $options);
            }
            if (is_callable($response)) {
                $response = $response($recorded);
            }
            if (! $response instanceof ResponseInterface && ! $response instanceof Throwable) {
                throw new LogicException('Unexpected Google Wallet test request.');
            }

            return (new MockHandler([$response]))($request, $options);
        };

        // record at the wire boundary, after the SDK has added its authentication headers
        $stack = HandlerStack::create(Middleware::history($this->history)($handler));
        $this->client = new Client(['handler' => $stack]);
    }

    /**
     * Configures endpoint fixtures while preserving previously configured routes.
     *
     * @param  array<string, mixed>|Closure  $responses  Explicit endpoint responses or one dynamic responder.
     */
    public function respondWith(array|Closure $responses): void
    {
        $this->responses = is_array($responses) && is_array($this->responses)
            ? array_replace($this->responses, $responses)
            : $responses;
    }

    /**
     * Exposes actual serialized requests through Laravel's native PSR request reader.
     *
     * @param  callable|null  $filter  Optional predicate over each native request reader.
     * @return Collection<int, array{0: Request, 1: ResponseInterface|null}> Recorded request/response pairs.
     */
    public function requests(?callable $filter = null): Collection
    {
        return collect($this->history)
            ->map(fn (array $entry) => [new Request($entry['request']), $entry['response'] ?? null])
            ->filter(fn (array $pair) => $filter === null || $filter($pair[0]))
            ->values();
    }

    /**
     * Creates a provider JSON response without performing a network operation.
     *
     * @param  array<string|int, mixed>|string  $body  Structured provider fixture or deliberately malformed bytes.
     * @param  int  $status  Provider response status.
     * @return Response Native PSR response consumed by the production integration.
     *
     * @throws \JsonException If a structured fixture cannot be encoded.
     */
    public static function json(array|string $body = [], int $status = 200): Response
    {
        $json = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

        return new Response($status, ['Content-Type' => 'application/json'], $json);
    }
}
