<?php

declare(strict_types=1);

namespace Maatify\Paymob\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class GuzzleApiClient implements ApiClientInterface
{
    private Client $client;

    public function __construct(PaymobConfig $config, private ?LoggerInterface $logger = null, private string $channel = 'paymob.guzzle')
    {
        if (!class_exists(Client::class)) throw new OptionalCapabilityUnavailableException('The Guzzle adapter requires guzzlehttp/guzzle ^7.0.');
        $this->client = new Client([
            'base_uri' => rtrim($config->baseUrl, '/') . '/',
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 30,
            'verify' => true,
        ]);
    }

    public function post(string $uri, array $body, array $headers = []): array { return $this->send('POST', $uri, ['json' => $body, 'headers' => $headers]); }
    public function get(string $uri, array $query = [], array $headers = []): array { return $this->send('GET', $uri, ['query' => $query, 'headers' => $headers]); }

    private function send(string $method, string $uri, array $options): array
    {
        try { $response = $this->client->request($method, ltrim($uri, '/'), $options); }
        catch (GuzzleException $e) {
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                if ($response !== null) return ResponseDecoder::decode($response->getStatusCode(), (string) $response->getBody());
            }
            throw new NetworkException('Paymob transport failed before an HTTP response was received.', previous: $e);
        }
        $status = $response->getStatusCode();
        $this->logger?->debug('Paymob HTTP response received.', ['method' => $method, 'uri' => parse_url($uri, PHP_URL_PATH) ?: '/', 'status' => $status]);
        return ResponseDecoder::decode($status, (string) $response->getBody());
    }
}
