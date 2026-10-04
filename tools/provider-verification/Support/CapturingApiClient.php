<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use JsonException;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Adapter\ApiClientInterface;
use RuntimeException;
use stdClass;
use Throwable;

/** Sends package-service requests over verified TLS and captures each exchange privately. */
final class CapturingApiClient implements ApiClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly CaptureSession $captureSession,
        private readonly ProviderAttemptStageClassifier $attemptStageClassifier,
        private readonly int $connectTimeoutSeconds = 15,
        private readonly int $timeoutSeconds = 45,
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The cURL extension is required for provider verification.');
        }
        if ($connectTimeoutSeconds < 1 || $timeoutSeconds < $connectTimeoutSeconds) {
            throw new RuntimeException('Provider verification timeouts must be finite and positive.');
        }
    }

    /** Classify one attempted request without executing or capturing provider traffic. */
    public function classifyAttemptStage(string $url, mixed $requestShape): string
    {
        return $this->attemptStageClassifier->classify($url, $requestShape);
    }

    /**
     * Checks request-option setup without executing or capturing a provider request.
     *
     * @throws RuntimeException when cURL is unavailable or the handle cannot be configured
     */
    public static function selfCheckTransportConfiguration(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The cURL extension is required for provider verification.');
        }

        $curl = curl_init('https://provider-verification-self-check.invalid/');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the provider verification transport self-check.');
        }

        $responseBody = '';
        $responseHeaderNames = [];
        self::configureTransportHandle(
            $curl,
            'POST',
            ['Content-Type: application/json'],
            '{}',
            15,
            45,
            $responseBody,
            $responseHeaderNames,
        );

        unset($curl);
    }

    /** Send a JSON POST through cURL and capture the raw exchange before decoding. */
    public function post(string $uri, array $body, array $headers = []): array
    {
        try {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Provider request could not be JSON encoded.', 0, $exception);
        }

        $headers = $this->ensureJsonContentType($headers);
        return $this->send('POST', $this->buildUrl($uri), $json, $headers, $body);
    }

    /** Send a GET with the supplied query and capture the raw exchange before decoding. */
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        $url = $this->buildUrl($uri);
        if ($query !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->send('GET', $url, '', $headers, $query);
    }

    private function send(string $method, string $url, string $requestBody, array $headers, array $requestShape): array
    {
        // Redirects are disabled, so this validated target is the actual provider URL.
        // Classify before creating or executing a transport handle; unknown requests never leave the process.
        $stage = $this->classifyAttemptStage($url, $requestShape);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new NetworkException('Could not initialize the provider verification transport.');
        }

        $responseBody = '';
        $responseHeaderNames = [];
        $headerLines = $this->formatHeaders($headers);
        self::configureTransportHandle(
            $curl,
            $method,
            $headerLines,
            $requestBody,
            $this->connectTimeoutSeconds,
            $this->timeoutSeconds,
            $responseBody,
            $responseHeaderNames,
        );

        $executionResult = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $requestHeaderBlock = (string)curl_getinfo($curl, CURLINFO_HEADER_OUT);
        $finalUrl = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        $transportOk = $executionResult !== false;
        $requestHeaderNames = $this->headerNamesFromBlock($requestHeaderBlock);
        unset($curl);

        $sequence = $this->captureSession->captureExchange([
            'stage' => $stage,
            'method' => $method,
            'final_url_raw' => $finalUrl !== '' ? $finalUrl : $url,
            'request_header_names' => $requestHeaderNames,
            'request_json_valid' => null,
            'request_body_structure' => $method === 'POST' ? $this->jsonStructure($requestShape) : null,
            'request_query_structure' => $method === 'GET' ? $this->jsonStructure($requestShape) : null,
            'http_status' => $httpStatus,
            'transport_ok' => $transportOk,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'response_header_names' => array_values(array_unique($responseHeaderNames)),
            'response_json_valid' => false,
            'response_top_level_type' => 'not-decoded',
            'response_top_level_keys' => [],
            'response_top_level_types' => [],
        ], $finalUrl !== '' ? $finalUrl : $url, $requestBody, $responseBody);

        $requestJsonValid = null;
        if ($method === 'POST') {
            json_decode($requestBody);
            $requestJsonValid = json_last_error() === JSON_ERROR_NONE;
        }

        $decodedResponse = null;
        $responseObject = null;
        $responseJsonValid = false;
        try {
            $decodedResponse = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            $responseObject = json_decode($responseBody, false, 512, JSON_THROW_ON_ERROR);
            $responseJsonValid = true;
        } catch (JsonException) {
            $responseJsonValid = false;
        }
        $responseValidForPackage = $responseJsonValid && is_array($decodedResponse);
        $responseTopLevelKeys = $responseObject instanceof stdClass ? array_keys(get_object_vars($responseObject)) : [];
        $responseTopLevelTypes = $responseObject instanceof stdClass
            ? $this->topLevelTypes($responseObject)
            : [];

        $this->captureSession->updateExchange($sequence, [
            'request_header_names' => $requestHeaderNames,
            'request_json_valid' => $requestJsonValid,
            'response_header_names' => array_values(array_unique($responseHeaderNames)),
            'transport_ok' => $transportOk,
            'http_status' => $httpStatus,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'response_json_valid' => $responseJsonValid,
            'response_top_level_type' => $responseJsonValid ? get_debug_type($responseObject) : 'invalid-json',
            'response_top_level_keys' => $responseTopLevelKeys,
            'response_top_level_types' => $responseTopLevelTypes,
        ]);

        if (!$transportOk) {
            throw new NetworkException('Provider verification transport failed.', $curlErrno);
        }

        if ($httpStatus >= 400) {
            throw new ApiException(
                'Provider returned an HTTP error status.',
                $httpStatus,
                $responseValidForPackage ? $decodedResponse : null,
            );
        }

        if (!$responseJsonValid) {
            throw new RuntimeException('Provider response was not valid JSON.');
        }
        if (!$responseValidForPackage) {
            throw new RuntimeException('Provider response JSON was not an object or array.');
        }

        return $decodedResponse;
    }

    /**
     * Applies the shared cURL request configuration; handle cleanup follows PHP object lifetime.
     *
     * @param resource|\CurlHandle $curl
     * @param list<string> $headerLines
     * @param list<string> $responseHeaderNames
     *
     * @throws RuntimeException when any cURL option cannot be configured
     */
    private static function configureTransportHandle(
        $curl,
        string $method,
        array $headerLines,
        string $requestBody,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        string &$responseBody,
        array &$responseHeaderNames,
    ): void {
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaderNames): int {
                    if (preg_match('/^HTTP\/\S+\s+\d{3}/i', $line) === 1) {
                        $responseHeaderNames = [];
                    } elseif (str_contains($line, ':')) {
                        $name = trim(strstr($line, ':', true));
                        if ($name !== '') {
                            $responseHeaderNames[] = $name;
                        }
                    }

                    return strlen($line);
                },
            ])) {
                throw new RuntimeException('Could not configure provider verification cURL options.');
            }

            if (!curl_setopt($curl, CURLINFO_HEADER_OUT, true)) {
                throw new RuntimeException('Could not enable provider verification request-header capture.');
            }

            if ($method === 'POST' && !curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody)) {
                throw new RuntimeException('Could not configure the provider verification POST body.');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Could not configure provider verification cURL transport.', 0, $exception);
        }
    }

    private function buildUrl(string $uri): string
    {
        if ($uri === '' || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $uri) === 1) {
            throw new RuntimeException('Provider service URI must be a relative path.');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($uri, '/');
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'accept.paymob.com') {
            throw new RuntimeException('Provider verification URL failed its Paymob Egypt API HTTPS host guard.');
        }

        return $url;
    }

    /** @return list<string> */
    private function ensureJsonContentType(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'content-type') {
                return $headers;
            }
            if (is_string($value) && preg_match('/^content-type\s*:/i', $value) === 1) {
                return $headers;
            }
        }

        $headers['Content-Type'] = 'application/json';
        return $headers;
    }

    /** @return list<string> */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = is_int($name) ? (string)$value : $name . ': ' . (string)$value;
        }

        return $formatted;
    }

    /** @return list<string> */
    private function headerNamesFromBlock(string $block): array
    {
        $names = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if (str_contains($line, ':')) {
                $name = trim(strstr($line, ':', true));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function jsonStructure(mixed $value): array|string
    {
        if (is_array($value)) {
            $structure = [];
            foreach ($value as $key => $child) {
                $structure[(string)$key] = $this->jsonStructure($child);
            }
            return $structure;
        }

        return get_debug_type($value);
    }

    private function topLevelTypes(stdClass $object): array
    {
        $types = [];
        foreach ($object as $key => $value) {
            $types[(string)$key] = get_debug_type($value);
        }

        return $types;
    }
}
