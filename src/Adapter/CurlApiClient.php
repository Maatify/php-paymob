<?php

declare(strict_types=1);

namespace Maatify\Paymob\Adapter;

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\NetworkException;
use Psr\Log\LoggerInterface;

final readonly class CurlApiClient implements ApiClientInterface
{
    public function __construct(private PaymobConfig $config, private ?LoggerInterface $logger = null, private string $channel = 'paymob.curl') {}

    public function post(string $uri, array $body, array $headers = []): array
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->request('POST', $uri, $payload, $headers);
    }

    public function get(string $uri, array $query = [], array $headers = []): array
    {
        $uri .= $query === [] ? '' : '?' . http_build_query($query);
        return $this->request('GET', $uri, null, $headers);
    }

    private function request(string $method, string $uri, ?string $body, array $headers): array
    {
        $url = $this->config->baseUrl . '/' . ltrim($uri, '/');
        $handle = curl_init($url);
        if ($handle === false) throw new NetworkException('Unable to initialize cURL.');
        $headerLines = ['Accept: application/json', 'Content-Type: application/json'];
        foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headerLines, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) throw new NetworkException('Paymob transport failed before an HTTP response was received: ' . $error);
        $this->logger?->debug('Paymob HTTP response received.', ['method' => $method, 'uri' => parse_url($uri, PHP_URL_PATH) ?: '/', 'status' => $status]);
        return ResponseDecoder::decode($status, $response);
    }
}
