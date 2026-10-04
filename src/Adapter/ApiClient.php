<?php

declare(strict_types=1);

namespace Maatify\Paymob\Adapter;

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiClient implements ApiClientInterface
{
    private ApiClientInterface $client;
    private ?LoggerInterface $logger;
    private string $channel;

    public function __construct(PaymobConfig $config, ?LoggerInterface $logger = null, string $channel = 'paymob.api-client', bool $useGuzzle = false)
    {
        $this->logger = $logger;
        $this->channel = $channel;
        if ($useGuzzle && !class_exists(\GuzzleHttp\Client::class)) throw new OptionalCapabilityUnavailableException('The Guzzle adapter requires guzzlehttp/guzzle ^7.0.');
        $this->client = $useGuzzle ? new GuzzleApiClient($config, $logger, $channel) : new CurlApiClient($config, $logger, $channel);
    }

    public function post(string $uri, array $body, array $headers = []): array { return $this->call('POST', $uri, fn () => $this->client->post($uri, $body, $headers)); }
    public function get(string $uri, array $query = [], array $headers = []): array { return $this->call('GET', $uri, fn () => $this->client->get($uri, $query, $headers)); }

    private function call(string $method, string $uri, callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $e) {
            $this->logger?->error("[{$this->channel}] {$method} request failed", ['method' => $method,
                'uri' => parse_url($uri, PHP_URL_PATH) ?: '/', 'exception' => $e::class,
                'provider_status' => method_exists($e, 'getProviderStatusCode') ? $e->getProviderStatusCode() : null]);
            throw $e;
        }
    }
}
