<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-06
 * Time: 12:57
 * Project: opay-checkout-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Http;

use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiClient implements ApiClientInterface
{
    private ApiClientInterface $client;

    public function __construct(
        PaymobConfigDTO $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $channel = 'paymob٫api-client',
        bool $useGuzzle = true
    ) {
        $this->client = $useGuzzle
            ? new GuzzleApiClient($config, $logger, $channel)
            : new CurlApiClient($config, $logger, $channel);
    }

    /**
     * @throws Throwable
     * @throws NetworkException
     * @throws ApiException
     */
    public function post(string $uri, array $body, array $headers = []): array
    {
        try {
            $response = $this->client->post($uri, $body, $headers);

            if (!$this->isValidResponse($response)) {
                $this->logger?->warning("[{$this->channel}] POST response missing expected keys", [
                    'uri'     => $uri,
                    'payload' => $body,
                    'resp'    => $response,
                ]);
            } else {
                $this->logger?->info("[{$this->channel}] POST success", [
                    'uri'     => $uri,
                    'payload' => $body,
                    'resp'    => $response,
                ]);
            }

            return $response;
        } catch (Throwable $e) {
            $this->logger?->error("[{$this->channel}] POST failed", [
                'uri'     => $uri,
                'payload' => $body,
                'error'   => $e->getMessage(),
            ]);
            throw $e; // نرمي نفس الـ Exception علشان يوصل للـ Service
        }
    }

    /**
     * @throws Throwable
     * @throws NetworkException
     * @throws ApiException
     */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->client->get($uri, $query, $headers);

            if (!$this->isValidResponse($response)) {
                $this->logger?->warning("[{$this->channel}] GET response missing expected keys", [
                    'uri'   => $uri,
                    'query' => $query,
                    'resp'  => $response,
                ]);
            } else {
                $this->logger?->info("[{$this->channel}] GET success", [
                    'uri'   => $uri,
                    'query' => $query,
                    'resp'  => $response,
                ]);
            }

            return $response;
        } catch (Throwable $e) {
            $this->logger?->error("[{$this->channel}] GET failed", [
                'uri'   => $uri,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Validate that response contains expected OPay keys
     */
    private function isValidResponse(array $response): bool
    {
        // أغلب ردود OPay بترجع: code, message, data
        return isset($response['code']) && isset($response['message']);
    }
}
