<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-06
 * Time: 12:55
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

final readonly class CurlApiClient implements ApiClientInterface
{
    public function __construct(
        private PaymobConfigDTO $config,
        private ?LoggerInterface $logger = null,
        private string $channel = 'paymob.curl'
    ) {}

    /**
     * @param   string  $uri
     * @param   array   $body
     * @param   array   $headers
     *
     * @return array
     * @throws ApiException
     * @throws NetworkException
     */
    public function post(string $uri, array $body, array $headers = []): array
    {
        $url = rtrim($this->config->baseUrl, '/') . $uri;

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);

        $headers = $this->normalizeHeaders($headers);

        $defaultHeaders = ['Content-Type: application/json'];
        $headers = array_merge($defaultHeaders, $headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER         => false,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            $this->logger?->error("[{$this->channel}] Network request failed", compact('url','body','error'));
            throw new NetworkException("Network error: $error");
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->logger?->warning("[{$this->channel}] Invalid JSON response", compact('url','body','result'));
            throw new ApiException("Invalid JSON response", $status, ['raw' => $result]);
        }

        if ($status !== 200) {
            $this->logger?->error("[{$this->channel}] API returned error", compact('url','body','decoded'));
            throw new ApiException("API Error", $status, $decoded);
        }

        $this->logger?->info("[{$this->channel}] Request success", compact('url','body','decoded'));
        return $decoded;
    }

    /**
     * @param   string  $uri
     * @param   array   $query
     * @param   array   $headers
     *
     * @return array
     * @throws ApiException
     * @throws NetworkException
     */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        $url = rtrim($this->config->baseUrl, '/') . $uri . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            $this->logger?->error("[{$this->channel}] Network request failed", compact('url','query','error'));
            throw new NetworkException("Network error: $error");
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            $this->logger?->warning("[{$this->channel}] Invalid JSON response", compact('url','query','result'));
            throw new ApiException("Invalid JSON response", $status, ['raw' => $result]);
        }

        if ($status !== 200) {
            $this->logger?->error("[{$this->channel}] API returned error", compact('url','query','decoded'));
            throw new ApiException("API Error", $status, $decoded);
        }

        $this->logger?->info("[{$this->channel}] Request success", compact('url','query','decoded'));
        return $decoded;
    }

    private function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }
        return $result;
    }

}

