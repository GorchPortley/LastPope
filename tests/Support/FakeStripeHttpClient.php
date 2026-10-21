<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Replaces stripe-php's HTTP transport so StripeGateway can be tested without a network: it records
 * every request (method, path, params, headers) and answers with canned JSON per "METHOD /v1/path".
 *
 *     $http = FakeStripeHttpClient::install();      // call FakeStripeHttpClient::uninstall() afterwards
 *     $http->respond('POST /v1/transfers', ['id' => 'tr_1', 'object' => 'transfer']);
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, path: string, params: array, headers: array<string, string>}> */
    public array $requests = [];

    /** @var array<string, array> */
    private array $responses = [];

    public static function install(): static
    {
        $client = new static;
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function respond(string $route, array $body, int $status = 200): static
    {
        $this->responses[$route] = [$body, $status];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $parsedHeaders = [];
        foreach ($headers as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $parsedHeaders[strtolower(trim($name))] = trim($value);
        }

        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => $params ?? [], 'headers' => $parsedHeaders];

        [$body, $status] = $this->responses[strtoupper($method).' '.$path] ?? [['id' => 'obj_default', 'object' => 'unknown'], 200];

        return [json_encode($body), $status, []];
    }

    /** The last request to "METHOD /v1/path", or null. */
    public function last(string $route): ?array
    {
        $matches = array_values(array_filter(
            $this->requests,
            fn (array $r) => $r['method'].' '.$r['path'] === $route,
        ));

        return $matches === [] ? null : end($matches);
    }
}
