<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-06
 * Time: 12:56
 * Project: opay-checkout-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Psr\Log\LoggerInterface;

final readonly class GuzzleApiClient implements ApiClientInterface
{
    private Client $client;

    public function __construct(
        private PaymobConfigDTO $config,
        private ?LoggerInterface $logger = null,
        private string $channel = 'paymob.guzzle'
    ) {
        $this->client = new Client(['base_uri' => $this->config->baseUrl]);
    }

    /**
     * @param   string  $uri
     * @param   array   $body
     * @param   array   $headers
     *
     * * @return array
     *
     * @throws NetworkException
     * @throws ApiException
     */
    public function post(string $uri, array $body, array $headers = []): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $defaultHeaders = ['Content-Type: application/json'];
        $headers = array_merge($defaultHeaders, $headers);
        try {
            $response = $this->client->post($uri, [
                'body'    => $payload,
//                'json'    => $body,
                'headers' => $headers,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            $this->logger?->error("[{$this->channel}] Network request failed", ['uri'=>$uri,'body'=>$body,'error'=>$e->getMessage()]);
            throw new NetworkException("Network error: {$e->getMessage()}");
        }

        $status = $response->getStatusCode();
        $result = (string)$response->getBody();
        $decoded = json_decode($result, true);

        if (!is_array($decoded)) {
            $this->logger?->warning("[{$this->channel}] Invalid JSON response", ['uri'=>$uri,'body'=>$body,'result'=>$result]);
            throw new ApiException("Invalid JSON response", $status, ['raw' => $result]);
        }

        if ($status > 400) {
            $this->logger?->error("[{$this->channel}] API returned error", ['uri'=>$uri,'body'=>$body,'decoded'=>$decoded]);
            throw new ApiException("API Error", $status, $decoded);
        }

        $this->logger?->info("[{$this->channel}] Request success", ['uri'=>$uri,'body'=>$body,'decoded'=>$decoded]);
        return $decoded;
    }

    /**
     * @param   string  $uri
     * @param   array   $query
     * @param   array   $headers
     *
     *  * @return array
     *
     * @throws NetworkException
     * @throws ApiException
     */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->client->get($uri, [
                'query'   => $query,
                'headers' => $headers,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            $this->logger?->error("[{$this->channel}] Network request failed", ['uri'=>$uri,'query'=>$query,'error'=>$e->getMessage()]);
            throw new NetworkException("Network error: {$e->getMessage()}");
        }

        $status = $response->getStatusCode();
        $result = (string)$response->getBody();
        $decoded = json_decode($result, true);

        if (!is_array($decoded)) {
            $this->logger?->warning("[{$this->channel}] Invalid JSON response", ['uri'=>$uri,'query'=>$query,'result'=>$result]);
            throw new ApiException("Invalid JSON response", $status, ['raw' => $result]);
        }

        if ($status > 400) {
            $this->logger?->error("[{$this->channel}] API returned error", ['uri'=>$uri,'query'=>$query,'decoded'=>$decoded]);
            throw new ApiException("API Error", $status, $decoded);
        }

        $this->logger?->info("[{$this->channel}] Request success", ['uri'=>$uri,'query'=>$query,'decoded'=>$decoded]);
        return $decoded;
    }


}
