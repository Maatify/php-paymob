<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use RuntimeException;
use stdClass;
use Throwable;

/** Builds sanitized reports from retained raw runs without executing provider requests. */
final class RetainedRunRecovery
{
    private string $stage = 'preflight';

    private string $scenario = 'unsupported';

    private int $kioskPaymentAttemptCount = 0;

    private ?string $sourceDirectory = null;

    private ?SemanticSanitizer $sanitizer = null;

    /** @var list<array<string, mixed>> */
    private array $rawArtifacts = [];

    /** @var list<array<string, mixed>> */
    private array $safeAttempts = [];

    /** @var array<string, array<string, array{path: string, bytes: int, sha256: string}>> */
    private array $filesBySequence = [];

    /**
     * Run the network-free recovery flow and return only safe data.
     *
     * @return array<string, mixed>
     */
    public static function recover(string $repositoryRoot, string $sourceDirectory, string $scenario): array
    {
        $recovery = new self();
        $recovery->scenario = in_array($scenario, ['wallet', 'kiosk'], true) ? $scenario : 'unsupported';
        try {
            return $recovery->recoverValidated($repositoryRoot, $sourceDirectory, $scenario);
        } catch (Throwable $exception) {
            return $recovery->persistFailure($exception);
        }
    }

    private function recoverValidated(string $repositoryRoot, string $sourceDirectory, string $scenario): array
    {
        $this->stage = 'path-validation';
        if (!in_array($scenario, ['wallet', 'kiosk'], true)) {
            throw new RuntimeException('The retained-run recovery scenario is not supported.');
        }
        if (!$this->isAbsolutePath($sourceDirectory) || is_link($sourceDirectory)) {
            throw new RuntimeException('The retained run path must be an absolute non-symlink directory.');
        }

        $temporaryRoot = realpath(sys_get_temp_dir());
        $repositoryRealPath = realpath($repositoryRoot);
        $namespacePath = $temporaryRoot === false
            ? ''
            : $temporaryRoot . DIRECTORY_SEPARATOR . 'maatify-paymob-provider-verification';
        if ($temporaryRoot === false || $repositoryRealPath === false || is_link($namespacePath)) {
            throw new RuntimeException('The retained run location could not be safely resolved.');
        }
        $namespaceRealPath = realpath($namespacePath);
        $runRealPath = realpath($sourceDirectory);
        if ($namespaceRealPath === false || dirname($namespaceRealPath) !== $temporaryRoot
            || $runRealPath === false || !is_dir($runRealPath)
            || dirname($runRealPath) !== $namespaceRealPath
            || preg_match('/^run-[A-Za-z0-9T._-]+$/', basename($runRealPath)) !== 1
            || $this->isWithin($runRealPath, $repositoryRealPath)
            || (fileperms($runRealPath) & 0777) !== 0700
            || (fileperms($namespaceRealPath) & 0777) !== 0700) {
            throw new RuntimeException('The retained run failed its location or permission guard.');
        }
        $this->sourceDirectory = $runRealPath;

        $this->stage = 'configuration';
        $config = VerificationConfig::load($repositoryRealPath, $scenario);
        if ($scenario === 'wallet'
            && ($config->walletIntegrationId === null || $config->walletTestMsisdn === null)) {
            throw new RuntimeException('Wallet recovery configuration is incomplete.');
        }
        if ($scenario === 'kiosk' && $config->kioskIntegrationId === null) {
            throw new RuntimeException('Kiosk recovery configuration is incomplete.');
        }
        $integrationId = $scenario === 'wallet' ? $config->walletIntegrationId : $config->kioskIntegrationId;
        $this->sanitizer = new SemanticSanitizer(
            $config->configuredSecrets(),
            $scenario === 'wallet' ? $config->walletTestMsisdn : null,
        );

        $this->stage = 'raw-file-discovery';
        $this->discoverTriplets();

        $this->stage = 'raw-file-reading';
        $decoded = [];
        $prime = [];
        foreach ($this->filesBySequence as $sequence => $files) {
            $urlBytes = $this->readVerifiedRaw($files['request-url']);
            $requestBytes = $this->readVerifiedRaw($files['request-body']);
            $responseBytes = $this->readVerifiedRaw($files['response-body']);
            $request = json_decode($requestBytes, false, 512, JSON_THROW_ON_ERROR);
            $response = json_decode($responseBytes, false, 512, JSON_THROW_ON_ERROR);
            if (!is_string($urlBytes) || trim($urlBytes) === '') {
                throw new RuntimeException('A retained request URL is empty or invalid.');
            }

            $stage = $this->deriveStage($urlBytes, $request, $scenario, (string)$integrationId);
            $decoded[] = [
                'sequence' => (int)$sequence,
                'stage' => $stage,
                'raw_url' => $urlBytes,
                'request_raw' => $request,
                'response_raw' => $response,
                'request_bytes' => $requestBytes,
                'response_bytes' => $responseBytes,
                'url_artifact' => $files['request-url'],
                'request_artifact' => $files['request-body'],
                'response_artifact' => $files['response-body'],
            ];
            $prime[] = $urlBytes;
            $prime[] = $request;
            $prime[] = $response;
        }

        $this->stage = 'sanitization';
        $sanitizer = $this->sanitizer;
        $sanitizer->prime($prime);
        $exchanges = [];
        foreach ($decoded as $entry) {
            $safeUrl = $sanitizer->sanitizeUrl($entry['raw_url'], '$.exchanges[' . $entry['sequence'] . '].final_url');
            $safeRequest = $sanitizer->sanitize(
                $entry['request_raw'],
                '',
                '$.exchanges[' . $entry['sequence'] . '].request',
            );
            $safeResponse = $sanitizer->sanitize(
                $entry['response_raw'],
                '',
                '$.exchanges[' . $entry['sequence'] . '].response',
            );
            if (!$sanitizer->sameShape($entry['request_raw'], $safeRequest)
                || !$sanitizer->sameShape($entry['response_raw'], $safeResponse)) {
                throw new RuntimeException('Recovered evidence changed JSON structure or scalar types.');
            }
            if ($sanitizer->semanticDifferences($entry['request_raw'], $safeRequest, '', '$.request') !== []
                || $sanitizer->semanticDifferences($entry['response_raw'], $safeResponse, '', '$.response') !== []) {
                throw new RuntimeException('Recovered evidence changed provider-semantic values.');
            }
            if ($sanitizer->sensitiveFieldDifferences($entry['request_raw'], $safeRequest, '', '$.request') !== []
                || $sanitizer->sensitiveFieldDifferences($entry['response_raw'], $safeResponse, '', '$.response') !== []) {
                throw new RuntimeException('Recovered evidence did not redact sensitive fields.');
            }

            $exchange = [
                'sequence' => $entry['sequence'],
                'stage' => $entry['stage'],
                'method' => null,
                'metadata_source' => 'unavailable_from_retained_raw',
                'final_url' => $safeUrl,
                'request_header_names' => null,
                'sanitized_request' => $safeRequest,
                'request_body_bytes' => strlen($entry['request_bytes']),
                'request_body_sha256' => hash('sha256', $entry['request_bytes']),
                'request_url_bytes' => strlen($entry['raw_url']),
                'request_url_sha256' => hash('sha256', $entry['raw_url']),
                'request_json_valid' => true,
                'http_status' => null,
                'transport_ok' => null,
                'curl_errno' => null,
                'curl_error' => null,
                'response_header_names' => null,
                'response_body_bytes' => strlen($entry['response_bytes']),
                'response_body_sha256' => hash('sha256', $entry['response_bytes']),
                'response_json_valid' => true,
                'response_top_level_type' => get_debug_type($entry['response_raw']),
                'response_top_level_keys' => $this->topLevelKeys($entry['response_raw']),
                'response_top_level_types' => $this->topLevelTypes($entry['response_raw']),
                'sanitized_response_fixture_candidate' => $safeResponse,
            ];
            if ($sanitizer->containsSensitiveValues($exchange)) {
                throw new RuntimeException('Recovered exchange did not pass the shared leak guard.');
            }
            $exchanges[] = $exchange;
            $this->safeAttempts[] = [
                'sequence' => $entry['sequence'],
                'stage' => $entry['stage'],
                'method' => null,
                'final_url' => $safeUrl,
                'request_body_bytes' => $exchange['request_body_bytes'],
                'request_body_sha256' => $exchange['request_body_sha256'],
                'response_body_bytes' => $exchange['response_body_bytes'],
                'response_body_sha256' => $exchange['response_body_sha256'],
                'http_status' => null,
                'transport_ok' => null,
                'curl_errno' => null,
                'response_json_valid' => true,
                'metadata_source' => 'unavailable_from_retained_raw',
            ];
        }

        $mappingCheck = $this->mappingConsistency(
            $sanitizer->idMappingSummary(),
            $sanitizer->referenceMappingSummary(),
        );
        if (!$mappingCheck) {
            throw new RuntimeException('Recovered identifier mappings are not referentially consistent.');
        }

        $report = [
            'source' => [
                'source_run' => basename($this->sourceDirectory),
                'source_raw_directory' => $this->sourceDirectory,
                'recovered_at' => gmdate('c'),
                'scenario' => $scenario,
                'exchange_count' => count($exchanges),
                'source_raw_retained' => true,
            ],
            'exchanges' => $exchanges,
            'id_mappings' => $sanitizer->idMappingSummary(),
            'reference_mappings' => $sanitizer->referenceMappingSummary(),
            'leak_guard_result' => 'PASS',
            'referential_consistency_result' => 'PASS',
        ];
        if ($sanitizer->containsSensitiveValues($report)) {
            throw new RuntimeException('Recovered report did not pass the shared leak guard.');
        }

        $this->stage = 'artifact-persistence';
        $artifactName = basename($this->sourceDirectory)
            . '-recovered-' . $scenario . '-' . gmdate('Ymd\\THis\\Z')
            . '-' . bin2hex(random_bytes(8)) . '.json';
        $artifact = CaptureSession::persistJsonArtifact($report, $artifactName);
        $readBack = file_get_contents($artifact['path']);
        if (!is_string($readBack) || strlen($readBack) !== $artifact['bytes']
            || !hash_equals($artifact['sha256'], hash('sha256', $readBack))) {
            throw new RuntimeException('Recovery artifact read-back verification failed.');
        }
        $persistedReport = json_decode($readBack, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($persistedReport) || $sanitizer->containsSensitiveValues($persistedReport)) {
            throw new RuntimeException('Persisted recovery artifact failed JSON or leak-guard verification.');
        }
        if (!is_dir($this->sourceDirectory)) {
            throw new RuntimeException('The retained source run is no longer present after recovery.');
        }

        return [
            'result' => 'PASS',
            'scenario' => $scenario,
            'source_run' => basename($this->sourceDirectory),
            'source_directory' => $this->sourceDirectory,
            'exchange_count' => count($exchanges),
            'recovered_stages' => array_values(array_map(
                static fn(array $exchange): string => $exchange['stage'],
                $exchanges,
            )),
            'report' => $persistedReport,
            'artifact' => $artifact,
            'source_raw_retained' => true,
        ];
    }

    private function discoverTriplets(): void
    {
        $entries = scandir((string)$this->sourceDirectory);
        if (!is_array($entries)) {
            throw new RuntimeException('The retained run filenames could not be listed.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->sourceDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_file($path)) {
                throw new RuntimeException('The retained run contains a non-regular or symbolic-link entry.');
            }
            if (preg_match('/^(\d{4})-(request-url\.txt|request-body\.bin|response-body\.bin)$/', $entry, $matches) !== 1) {
                throw new RuntimeException('The retained run contains an unexpected filename.');
            }
            $sequence = (int)$matches[1];
            $kind = match ($matches[2]) {
                'request-url.txt' => 'request-url',
                'request-body.bin' => 'request-body',
                'response-body.bin' => 'response-body',
            };
            if (isset($this->filesBySequence[$sequence][$kind])) {
                throw new RuntimeException('The retained run contains a duplicate exchange file.');
            }

            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            $permissions = fileperms($path);
            if (!is_int($size) || !is_string($hash) || !is_int($permissions)
                || ($permissions & 0077) !== 0 || !is_readable($path)) {
                throw new RuntimeException('A retained evidence file failed its readability, size, hash, or permission check.');
            }
            $metadata = [
                'path' => $path,
                'bytes' => $size,
                'sha256' => $hash,
            ];
            $this->filesBySequence[$sequence][$kind] = $metadata;
            $this->rawArtifacts[] = [
                'filename' => $entry,
                'bytes' => $size,
                'sha256' => $hash,
            ];
        }

        if ($this->filesBySequence === []) {
            throw new RuntimeException('The retained run contains no exchange triplets.');
        }
        ksort($this->filesBySequence, SORT_NUMERIC);
        $expectedSequence = 1;
        foreach ($this->filesBySequence as $sequence => $files) {
            if ((int)$sequence !== $expectedSequence
                || count($files) !== 3
                || !isset($files['request-url'], $files['request-body'], $files['response-body'])) {
                throw new RuntimeException('The retained run has a missing triplet or non-contiguous sequence.');
            }
            $expectedSequence++;
        }
        usort($this->rawArtifacts, static fn(array $left, array $right): int => strcmp($left['filename'], $right['filename']));
    }

    private function readVerifiedRaw(array $metadata): string
    {
        $path = $metadata['path'];
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('A retained evidence file changed type during recovery.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || strlen($contents) !== $metadata['bytes']
            || !hash_equals($metadata['sha256'], hash('sha256', $contents))) {
            throw new RuntimeException('A retained evidence file failed read-back verification.');
        }

        return $contents;
    }

    private function deriveStage(string $url, mixed $request, string $scenario, string $integrationId): string
    {
        if (!$request instanceof stdClass) {
            throw new RuntimeException('A retained request body is not a JSON object.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'accept.paymob.com') {
            throw new RuntimeException('A retained request URL failed the provider host guard.');
        }
        $path = $parts['path'] ?? '';
        if ($path === '/api/auth/tokens' && property_exists($request, 'api_key')) {
            return 'auth';
        }
        if ($path === '/api/ecommerce/orders') {
            return 'order';
        }
        if ($path === '/api/acceptance/payment_keys'
            && property_exists($request, 'integration_id')
            && (string)$request->integration_id === $integrationId) {
            return $scenario === 'kiosk' ? 'payment-key-kiosk' : 'payment-key-wallet';
        }
        if ($scenario === 'kiosk'
            && $path === '/api/acceptance/payments/pay'
            && isset($request->source) && $request->source instanceof stdClass
            && ($request->source->identifier ?? null) === 'AGGREGATOR'
            && ($request->source->subtype ?? null) === 'AGGREGATOR'
            && property_exists($request, 'payment_token')
            && property_exists($request, 'auth_token')) {
            $this->kioskPaymentAttemptCount++;
            return match ($this->kioskPaymentAttemptCount) {
                1 => 'kiosk-payment',
                2 => 'kiosk-payment-retry',
                default => throw new RuntimeException('The retained run contains too many Kiosk payment attempts.'),
            };
        }
        if ($scenario === 'wallet'
            && $path === '/api/acceptance/payments/pay'
            && isset($request->source) && $request->source instanceof stdClass
            && isset($request->source->subtype) && $request->source->subtype === 'WALLET') {
            return property_exists($request, 'auth_token')
                ? 'wallet-initiation-retry'
                : 'wallet-initiation';
        }

        throw new RuntimeException('A retained exchange could not be classified deterministically.');
    }

    private function topLevelKeys(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return array_keys(get_object_vars($value));
        }
        return is_array($value) ? array_keys($value) : [];
    }

    private function topLevelTypes(mixed $value): array
    {
        $fields = $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
        $types = [];
        foreach ($fields as $key => $field) {
            $types[$key] = get_debug_type($field);
        }
        return $types;
    }

    private function mappingConsistency(array $idMappings, array $referenceMappings): bool
    {
        foreach ([$idMappings, $referenceMappings] as $mappings) {
            $seenValues = [];
            foreach ($mappings as $mapping) {
                $value = (string)($mapping['fake_value'] ?? '');
                if ($value === '' || isset($seenValues[$value]) || ($mapping['paths'] ?? []) === []) {
                    return false;
                }
                $seenValues[$value] = true;
            }
        }
        return true;
    }

    private function persistFailure(Throwable $exception): array
    {
        $safeMessage = 'Offline retained-run recovery failed during ' . $this->stage . '.';
        $diagnostic = [
            'result' => 'FAIL',
            'scenario' => $this->scenario,
            'failing_stage' => $this->stage,
            'exception_class' => get_class($exception),
            'exception_message' => $this->sanitizer?->sanitizeDiagnostic($safeMessage) ?? $safeMessage,
            'raw_evidence_retained' => $this->sourceDirectory !== null && is_dir($this->sourceDirectory),
            'raw_storage_directory' => $this->sourceDirectory,
            'raw_artifacts' => $this->rawArtifacts,
            'captured_attempts' => $this->safeAttempts,
        ];
        $sanitizer = $this->sanitizer;
        if ($sanitizer !== null && $sanitizer->containsSensitiveValues($diagnostic)) {
            $diagnostic['exception_message'] = 'Offline retained-run recovery failed.';
        }

        try {
            $fileName = 'recovery-failure-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6)) . '.json';
            $artifact = CaptureSession::persistJsonArtifact($diagnostic, $fileName);
            $readBack = file_get_contents($artifact['path']);
            if (!is_string($readBack) || strlen($readBack) !== $artifact['bytes']
                || !hash_equals($artifact['sha256'], hash('sha256', $readBack))) {
                throw new RuntimeException('Failure diagnostic read-back verification failed.');
            }
            $persisted = json_decode($readBack, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($persisted) || ($sanitizer !== null && $sanitizer->containsSensitiveValues($persisted))) {
                throw new RuntimeException('Failure diagnostic verification failed.');
            }

            return [
                'result' => 'FAIL',
                'scenario' => $this->scenario,
                'source_run' => $this->sourceDirectory === null ? null : basename($this->sourceDirectory),
                'source_directory' => $this->sourceDirectory,
                'exchange_count' => count($this->filesBySequence),
                'recovered_stages' => array_values(array_map(
                    static fn(array $attempt): string => $attempt['stage'],
                    $this->safeAttempts,
                )),
                'failure_artifact' => $artifact,
                'source_raw_retained' => $this->sourceDirectory !== null && is_dir($this->sourceDirectory),
            ];
        } catch (Throwable) {
            return [
                'result' => 'FAIL',
                'scenario' => $this->scenario,
                'source_run' => $this->sourceDirectory === null ? null : basename($this->sourceDirectory),
                'source_directory' => $this->sourceDirectory,
                'exchange_count' => count($this->filesBySequence),
                'recovered_stages' => [],
                'failure_artifact' => null,
                'source_raw_retained' => $this->sourceDirectory !== null && is_dir($this->sourceDirectory),
            ];
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\/]/', $path) === 1;
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
