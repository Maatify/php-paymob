<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use JsonException;
use RuntimeException;
use Throwable;

/** Stores private raw exchanges and releases only validated sanitized evidence. */
final class CaptureSession
{
    /** @var list<array<string, mixed>> */
    private array $exchanges = [];

    private string $rawDirectory;

    public function __construct(string $repositoryRoot)
    {
        $root = realpath($repositoryRoot);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($root === false || $temporaryRoot === false || $this->isWithin($temporaryRoot, $root)) {
            throw new RuntimeException('The operating-system temporary directory is not outside the repository.');
        }

        $namespaceDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'maatify-paymob-provider-verification';
        if (is_link($namespaceDirectory)) {
            throw new RuntimeException('The private provider-evidence directory cannot be a symbolic link.');
        }
        if (!is_dir($namespaceDirectory) && !mkdir($namespaceDirectory, 0700) && !is_dir($namespaceDirectory)) {
            throw new RuntimeException('Could not create the private provider-evidence directory.');
        }
        chmod($namespaceDirectory, 0700);
        clearstatcache(true, $namespaceDirectory);
        $namespaceRealPath = realpath($namespaceDirectory);
        if ($namespaceRealPath === false || !$this->isWithin($namespaceRealPath, $temporaryRoot)
            || $this->isWithin($namespaceRealPath, $root)
            || (fileperms($namespaceRealPath) & 0777) !== 0700) {
            throw new RuntimeException('The private provider-evidence directory failed its location or permission guard.');
        }

        $runName = 'run-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(10));
        $runDirectory = $namespaceRealPath . DIRECTORY_SEPARATOR . $runName;
        if (!mkdir($runDirectory, 0700)) {
            throw new RuntimeException('Could not create a provider-evidence run directory.');
        }
        chmod($runDirectory, 0700);
        clearstatcache(true, $runDirectory);
        $runRealPath = realpath($runDirectory);
        if ($runRealPath === false || is_link($runDirectory) || !$this->isWithin($runRealPath, $namespaceRealPath)
            || $this->isWithin($runRealPath, $root)
            || (fileperms($runRealPath) & 0777) !== 0700) {
            throw new RuntimeException('The provider-evidence run directory failed its location or permission guard.');
        }

        $this->rawDirectory = $runRealPath;
    }

    /** Persist raw bytes before response decoding or service-level error handling. */
    public function captureExchange(array $metadata, string $requestUrl, string $requestBody, string $responseBody): int
    {
        $sequence = count($this->exchanges) + 1;
        $prefix = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        $requestUrlFile = $this->writeRaw($prefix . '-request-url.txt', $requestUrl);
        $requestBodyFile = $this->writeRaw($prefix . '-request-body.bin', $requestBody);
        $responseBodyFile = $this->writeRaw($prefix . '-response-body.bin', $responseBody);

        $this->exchanges[] = array_merge($metadata, [
            'sequence' => $sequence,
            'request_url_raw' => $requestUrl,
            'request_url_file' => $requestUrlFile,
            'request_body_file' => $requestBodyFile,
            'response_body_file' => $responseBodyFile,
            'request_body_bytes' => strlen($requestBody),
            'request_body_sha256' => hash('sha256', $requestBody),
            'response_body_bytes' => strlen($responseBody),
            'response_body_sha256' => hash('sha256', $responseBody),
        ]);

        return $sequence;
    }

    /** @param array<string, mixed> $metadata */
    public function updateExchange(int $sequence, array $metadata): void
    {
        $index = $sequence - 1;
        if (!isset($this->exchanges[$index])) {
            throw new RuntimeException('The provider exchange record was not found.');
        }

        $this->exchanges[$index] = array_merge($this->exchanges[$index], $metadata);
    }

    /** Return the private temporary directory used for this capture session. */
    public function rawDirectory(): string
    {
        return $this->rawDirectory;
    }

    /**
     * @return array{exchanges: list<array<string, mixed>>, id_mappings: list<array<string, mixed>>, reference_mappings: list<array<string, mixed>>}
     */
    public function buildSanitizedReport(SemanticSanitizer $sanitizer): array
    {
        $decoded = [];
        $prime = [];

        foreach ($this->exchanges as $index => $exchange) {
            $requestBody = $this->readVerified($exchange['request_body_file'], $exchange['request_body_bytes'], $exchange['request_body_sha256']);
            $responseBody = $this->readVerified($exchange['response_body_file'], $exchange['response_body_bytes'], $exchange['response_body_sha256']);
            $requestValue = null;
            if ($requestBody !== '') {
                $requestValue = json_decode($requestBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Captured request JSON could not be decoded.');
                }
            }

            $responseValue = json_decode($responseBody);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('A captured provider response is not valid JSON.');
            }

            $decoded[$index] = [
                'exchange' => $exchange,
                'request' => $requestValue,
                'response' => $responseValue,
                'raw_url' => $this->readVerified($exchange['request_url_file'], null, null),
            ];
            $prime[] = $requestValue;
            $prime[] = $responseValue;
            $prime[] = $decoded[$index]['raw_url'];
        }

        $sanitizer->prime($prime);
        $safeExchanges = [];

        foreach ($decoded as $entry) {
            $exchange = $entry['exchange'];
            $rawRequest = $entry['request'];
            $rawResponse = $entry['response'];
            $safeRequest = $rawRequest === null ? null : $sanitizer->sanitize($rawRequest, '', '$.request');
            $safeResponse = $sanitizer->sanitize($rawResponse, '', '$.response');

            if ($rawRequest !== null && !$sanitizer->sameShape($rawRequest, $safeRequest)) {
                throw new RuntimeException('Sanitized request structure or types differ from the captured request.');
            }
            if (!$sanitizer->sameShape($rawResponse, $safeResponse)) {
                throw new RuntimeException('Sanitized response structure or types differ from the provider response.');
            }

            $semanticDifferences = [];
            if ($rawRequest !== null) {
                $semanticDifferences = array_merge(
                    $semanticDifferences,
                    $sanitizer->semanticDifferences($rawRequest, $safeRequest, '', '$.request'),
                );
            }
            $semanticDifferences = array_merge(
                $semanticDifferences,
                $sanitizer->semanticDifferences($rawResponse, $safeResponse, '', '$.response'),
            );
            if ($semanticDifferences !== []) {
                throw new RuntimeException('Sanitized evidence changed provider-semantic values.');
            }

            $safeEnvelope = [
                'request_url' => $sanitizer->sanitizeUrl($entry['raw_url'], '$.request_url'),
                'request' => $safeRequest,
                'response' => $safeResponse,
            ];
            if ($sanitizer->containsSensitiveValues($safeEnvelope)) {
                throw new RuntimeException('Sanitized evidence contains a configured secret or detected PII value.');
            }

            $safeExchanges[] = [
                'sequence' => $exchange['sequence'],
                'stage' => $exchange['stage'],
                'method' => $exchange['method'],
                'final_url' => $sanitizer->sanitizeUrl((string)$exchange['final_url_raw'], '$.final_url'),
                'request_header_names' => $exchange['request_header_names'],
                'request_json_valid' => $exchange['request_json_valid'],
                'request_body_structure' => $exchange['request_body_structure'],
                'request_query_structure' => $exchange['request_query_structure'],
                'request_body_bytes' => $exchange['request_body_bytes'],
                'request_body_sha256' => $exchange['request_body_sha256'],
                'http_status' => $exchange['http_status'],
                'transport_ok' => $exchange['transport_ok'],
                'curl_errno' => $exchange['curl_errno'],
                'curl_error' => $sanitizer->sanitizeDiagnostic((string)$exchange['curl_error']),
                'response_header_names' => $exchange['response_header_names'],
                'response_body_bytes' => $exchange['response_body_bytes'],
                'response_body_sha256' => $exchange['response_body_sha256'],
                'response_json_valid' => $exchange['response_json_valid'],
                'response_top_level_type' => $exchange['response_top_level_type'],
                'response_top_level_keys' => $exchange['response_top_level_keys'],
                'response_top_level_types' => $exchange['response_top_level_types'],
                'sanitized_response_fixture_candidate' => $safeResponse,
                'tls_peer_verification' => true,
                'tls_host_verification' => 2,
                'redirect_following' => false,
            ];
        }

        return [
            'exchanges' => $safeExchanges,
            'id_mappings' => $sanitizer->idMappingSummary(),
            'reference_mappings' => $sanitizer->referenceMappingSummary(),
        ];
    }

    /** Remove raw files only after all capture and sanitization checks pass. */
    public function cleanupRawEvidence(): bool
    {
        foreach (glob($this->rawDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                return false;
            }
        }

        return rmdir($this->rawDirectory) && !file_exists($this->rawDirectory);
    }

    /** Build a sanitized failure report and retain every raw artifact for diagnosis. */
    public function failureDiagnostic(string $stage, Throwable $exception, array $configuredSecrets = []): array
    {
        $sanitizer = new SemanticSanitizer($configuredSecrets);
        $prime = [];
        foreach ($this->exchanges as $exchange) {
            foreach (['request_body_file', 'response_body_file'] as $field) {
                $bytes = @file_get_contents((string)$exchange[$field]);
                if (is_string($bytes)) {
                    $decoded = json_decode($bytes);
                    $prime[] = json_last_error() === JSON_ERROR_NONE ? $decoded : $bytes;
                }
            }
            $prime[] = $exchange['request_url_raw'] ?? '';
        }
        $sanitizer->prime($prime);

        return [
            'result' => 'FAIL',
            'failing_stage' => $stage,
            'exception_class' => get_class($exception),
            'exception_message' => $sanitizer->sanitizeDiagnostic($exception->getMessage()),
            'raw_evidence_retained' => true,
            'raw_storage_directory' => $this->rawDirectory,
            'raw_artifacts' => $this->rawArtifactSummary(),
            'captured_attempts' => $this->safeAttemptSummary($sanitizer),
        ];
    }

    /** @return list<array{path: string, bytes: int, sha256: string}> */
    private function rawArtifactSummary(): array
    {
        $artifacts = [];
        foreach (glob($this->rawDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $size = filesize($file);
            $hash = hash_file('sha256', $file);
            if (is_int($size) && is_string($hash)) {
                $artifacts[] = ['path' => $file, 'bytes' => $size, 'sha256' => $hash];
            }
        }

        return $artifacts;
    }

    private function safeAttemptSummary(SemanticSanitizer $sanitizer): array
    {
        $attempts = [];
        foreach ($this->exchanges as $exchange) {
            $attempts[] = [
                'sequence' => $exchange['sequence'],
                'stage' => $exchange['stage'],
                'method' => $exchange['method'],
                'final_url' => $sanitizer->sanitizeUrl((string)$exchange['final_url_raw'], '$.final_url'),
                'request_header_names' => $exchange['request_header_names'],
                'request_body_structure' => $exchange['request_body_structure'],
                'request_query_structure' => $exchange['request_query_structure'],
                'http_status' => $exchange['http_status'],
                'transport_ok' => $exchange['transport_ok'],
                'curl_errno' => $exchange['curl_errno'],
                'curl_error' => $sanitizer->sanitizeDiagnostic((string)$exchange['curl_error']),
                'response_header_names' => $exchange['response_header_names'],
                'response_body_bytes' => $exchange['response_body_bytes'],
                'response_body_sha256' => $exchange['response_body_sha256'],
                'response_json_valid' => $exchange['response_json_valid'],
            ];
        }

        return $attempts;
    }

    private function writeRaw(string $name, string $contents): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new RuntimeException('Invalid raw evidence file name.');
        }

        $path = $this->rawDirectory . DIRECTORY_SEPARATOR . $name;
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('Could not create a private raw evidence file.');
        }

        chmod($path, 0600);
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600) {
            fclose($file);
            @unlink($path);
            throw new RuntimeException('Raw evidence file permissions do not meet the private-mode requirement.');
        }

        $length = strlen($contents);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($file, substr($contents, $written));
            if ($count === false || $count === 0) {
                fclose($file);
                throw new RuntimeException('Raw evidence file write was incomplete.');
            }
            $written += $count;
        }

        fflush($file);
        if (function_exists('fsync')) {
            fsync($file);
        }
        fclose($file);
        clearstatcache(true, $path);

        $saved = file_get_contents($path);
        if (!is_string($saved) || strlen($saved) !== $length
            || !hash_equals(hash('sha256', $contents), hash('sha256', $saved))) {
            throw new RuntimeException('Raw evidence file failed byte-count or SHA-256 verification.');
        }

        return $path;
    }

    private function readVerified(string $path, ?int $expectedBytes, ?string $expectedHash): string
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('A raw evidence file could not be read.');
        }

        if ($expectedBytes !== null && strlen($contents) !== $expectedBytes) {
            throw new RuntimeException('A raw evidence file byte count changed after capture.');
        }
        if ($expectedHash !== null && !hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new RuntimeException('A raw evidence file SHA-256 changed after capture.');
        }

        return $contents;
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
