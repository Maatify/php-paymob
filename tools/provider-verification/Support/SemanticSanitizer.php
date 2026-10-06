<?php

declare(strict_types=1);

namespace Maatify\Paymob\ProviderVerification\Support;

use JsonException;
use stdClass;

/** Sanitizes provider evidence while preserving JSON structure and semantics. */
final class SemanticSanitizer
{
    /** @var list<string> Existing private references whose digit-only strings retain numeric ID mapping. */
    private const NUMERIC_PRIVATE_REFERENCE_ID_KEYS = [
        'merchant_order_id',
        'other_endpoint_reference',
        'mer_txn_ref',
        'order_info',
        'bill_reference',
    ];

    /** @var array<string, true> */
    private array $embeddedSensitiveValues = [];

    /** @var array<string, true> */
    private array $reservedNumericIds = [];

    /** @var array<string, true> */
    private array $reservedIdValues = [];

    /** @var array<string, array{fake: int|string|float, paths: list<string>, original_type: string}> */
    private array $idMappings = [];

    /** @var array<string, string> */
    private array $usedFakeIds = [];

    /** @var array<string, array{fake: string, paths: list<string>}> */
    private array $referenceMappings = [];

    private int $nextId = 900001;

    /**
     * @param list<string> $configuredSecrets
     */
    public function __construct(
        array $configuredSecrets = [],
        private readonly ?string $publicWalletTestMsisdn = null,
    ) {
        foreach ($configuredSecrets as $secret) {
            if ($secret !== '') {
                $this->embeddedSensitiveValues['secret:' . $secret] = true;
            }
        }
    }

    /** Seed global leak-guard literals and ID detection before sanitization; address/name values stay path-scoped. */
    public function prime(array $values): void
    {
        foreach ($values as $value) {
            $this->collectGlobalLeakGuardValues($value);
        }
    }

    /** Return a sanitized value without changing its JSON type or container shape. */
    public function sanitize(mixed $value, string $key = '', string $path = '$'): mixed
    {
        if ($this->isSensitiveKey($key) || $this->isContextSensitiveKey($key, $path)) {
            return $this->redactSubtree($value, $key, $path);
        }

        if ($value instanceof stdClass) {
            $safe = new stdClass();
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                $safe->{$name} = $this->sanitize($childValue, $name, $path . '.' . $name);
            }

            return $safe;
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                $childPath = array_is_list($value) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                $safe[$childKey] = $this->sanitize($childValue, $name, $childPath);
            }

            return $safe;
        }

        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            if ($this->isPhoneCollectionElement($path)) {
                return is_float($value) ? 20000000000.0 : 20000000000;
            }

            if ($this->isIdKey($key, $value) || $this->isIntentionIntegrationId($path)) {
                return $this->mapId($value, $path);
            }

            if ($this->isPrivateReferenceKey($key)) {
                return $this->mapId($value, $path);
            }

            if ($this->isPiiKey($key, $path)) {
                if ($this->isPhoneKey($key)) {
                    return is_float($value) ? 20000000000.0 : 20000000000;
                }
                if ($this->isIpKey($key)) {
                    return $this->redactedNumber($value);
                }

                return $this->redactedNumber($value);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($this->isPhoneCollectionElement($path)) {
            return '+20000000000';
        }

        if ($this->isIdKey($key, $value) || $this->isIntentionIntegrationId($path)) {
            return $this->mapId($value, $path);
        }

        if ($this->isPrivateReferenceKey($key)) {
            if ($this->usesNumericIdMappingForPrivateReference($key, $value)) {
                return $this->mapId($value, $path);
            }

            return $this->mapReference($value, $key, $path);
        }

        if ($this->isUrlKey($key) || filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return $this->sanitizeUrl($value, $path);
        }

        if ($this->isWalletTestPath($path) && $value === $this->publicWalletTestMsisdn) {
            return $value;
        }

        if ($this->isEmailKey($key)) {
            return 'customer@example.test';
        }

        if ($this->isPhoneKey($key)) {
            return '+20000000000';
        }

        if ($this->isNameKey($key, $path)) {
            return '<SANITIZED_NAME>';
        }

        if ($this->isAddressKey($key, $path)) {
            return '<SANITIZED_ADDRESS>';
        }

        if ($this->isIpKey($key)) {
            return '192.0.2.1';
        }

        return $this->redactEmbeddedSensitiveText($value);
    }

    /** Replace sensitive URL data while retaining the URL and query-key structure. */
    public function sanitizeUrl(string $url, string $path = '$.url'): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return 'https://example.test/redacted';
        }

        $scheme = isset($parts['scheme']) && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            ? strtolower((string)$parts['scheme'])
            : 'https';
        $host = isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            ? (string)$parts['host']
            : 'example.test';
        if ($this->redactEmbeddedSensitiveText($host) !== $host || $this->looksLikeSecretToken($host)) {
            $host = 'example.test';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $segments = explode('/', (string)($parts['path'] ?? '/'));
        $transactionIdPath = preg_match(
            '~^/api/acceptance/transactions/[1-9][0-9]*$~D',
            (string)($parts['path'] ?? ''),
        ) === 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);
            if ($this->looksLikeSecretToken($decoded)) {
                $segments[$index] = '%3CREDACTED_SECRET%3E';
            } elseif (ctype_digit($decoded) && (($transactionIdPath && $index === 4) || strlen($decoded) >= 5)) {
                $segments[$index] = rawurlencode((string)$this->mapId($decoded, $path . '.path[' . $index . ']'));
            } else {
                $segments[$index] = rawurlencode($this->redactEmbeddedSensitiveText($decoded));
            }
        }

        $safePath = implode('/', $segments);
        $queryPairs = [];
        if (isset($parts['query'])) {
            foreach (explode('&', (string)$parts['query']) as $pairIndex => $pair) {
                if ($pair === '') {
                    continue;
                }

                $bits = explode('=', $pair, 2);
                $name = rawurldecode($bits[0]);
                $hasValue = count($bits) === 2;
                $rawValue = $hasValue ? rawurldecode($bits[1]) : '';
                $queryPath = $path . '.query.' . $name . '[' . $pairIndex . ']';

                if ($this->isSensitiveKey($name)) {
                    $safeValue = '<REDACTED_SECRET>';
                } elseif ($this->isIdKey($name, $rawValue) && $rawValue !== '') {
                    $safeValue = (string)$this->mapId($rawValue, $queryPath);
                } elseif ($this->isPrivateReferenceKey($name) && $rawValue !== '') {
                    $safeValue = $this->usesNumericIdMappingForPrivateReference($name, $rawValue)
                        ? $this->mapId($rawValue, $queryPath)
                        : $this->mapReference($rawValue, $name, $queryPath);
                } else {
                    $safeValue = $this->redactEmbeddedSensitiveText($rawValue);
                }

                $queryPairs[] = rawurlencode($name) . ($hasValue ? '=' . rawurlencode($safeValue) : '');
            }
        }

        $fragment = isset($parts['fragment'])
            ? '#' . rawurlencode($this->looksLikeSecretToken((string)$parts['fragment'])
                ? '<REDACTED_SECRET>'
                : $this->redactEmbeddedSensitiveText((string)$parts['fragment']))
            : '';

        return $scheme . '://' . $host . $port . $safePath
            . ($queryPairs === [] ? '' : '?' . implode('&', $queryPairs))
            . $fragment;
    }

    /** Compare exact JSON structure and primitive types. */
    public function sameShape(mixed $raw, mixed $safe): bool
    {
        if (get_debug_type($raw) !== get_debug_type($safe)) {
            return false;
        }

        if ($raw instanceof stdClass && $safe instanceof stdClass) {
            $rawKeys = array_keys(get_object_vars($raw));
            $safeKeys = array_keys(get_object_vars($safe));
            if ($rawKeys !== $safeKeys) {
                return false;
            }

            foreach ($rawKeys as $key) {
                if (!$this->sameShape($raw->{$key}, $safe->{$key})) {
                    return false;
                }
            }
        } elseif (is_array($raw) && is_array($safe)) {
            if (array_keys($raw) !== array_keys($safe)) {
                return false;
            }

            foreach ($raw as $key => $value) {
                if (!$this->sameShape($value, $safe[$key])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Return paths where provider-semantic values differ from sanitizer output. */
    public function semanticDifferences(mixed $raw, mixed $safe, string $key = '', string $path = '$'): array
    {
        $differences = [];
        if ($raw instanceof stdClass && $safe instanceof stdClass) {
            foreach ($raw as $childKey => $value) {
                $name = (string)$childKey;
                $childPath = $path . '.' . $name;
                $differences = array_merge(
                    $differences,
                    $this->semanticDifferences($value, $safe->{$name}, $name, $childPath),
                );
            }
        } elseif (is_array($raw) && is_array($safe)) {
            foreach ($raw as $childKey => $value) {
                $name = (string)$childKey;
                $childPath = array_is_list($raw) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                $differences = array_merge(
                    $differences,
                    $this->semanticDifferences($value, $safe[$childKey], $name, $childPath),
                );
            }
        } elseif ($this->isSemanticKey($key) && $raw !== $safe) {
            $differences[] = $path;
        }

        return $differences;
    }

    /** Return sensitive field paths that still expose their original scalar value. */
    public function sensitiveFieldDifferences(mixed $raw, mixed $safe, string $key = '', string $path = '$'): array
    {
        if (!$this->sameShape($raw, $safe)) {
            return [$path];
        }

        return $this->collectSensitiveFieldDifferences($raw, $safe, $key, $path);
    }

    /** @return list<string> */
    private function collectSensitiveFieldDifferences(mixed $raw, mixed $safe, string $key, string $path): array
    {
        $differences = [];
        if (($this->isSensitiveKey($key) || $this->isContextSensitiveKey($key, $path) || $this->isContextualPiiKey($key, $path)
                || $this->isEmailKey($key) || $this->isPhoneKey($key))
            && $this->hasSensitiveScalarValue($raw)
            && !($this->isWalletTestPath($path) && $raw === $this->publicWalletTestMsisdn)
            && !$this->isExpectedSensitiveScalarReplacement($key, $path, $raw, $safe)) {
            $differences[] = $path;
        }

        if ($raw instanceof stdClass && $safe instanceof stdClass) {
            foreach ($raw as $childKey => $value) {
                $name = (string)$childKey;
                $differences = array_merge(
                    $differences,
                    $this->collectSensitiveFieldDifferences($value, $safe->{$name}, $name, $path . '.' . $name),
                );
            }
        } elseif (is_array($raw) && is_array($safe)) {
            foreach ($raw as $childKey => $value) {
                $name = (string)$childKey;
                $childPath = array_is_list($raw) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                $differences = array_merge(
                    $differences,
                    $this->collectSensitiveFieldDifferences($value, $safe[$childKey], $name, $childPath),
                );
            }
        }

        return array_values(array_unique($differences));
    }

    /** Check serialized sanitized evidence for global secrets and embedded PII or token values. */
    public function containsSensitiveValues(mixed $value): bool
    {
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return true;
        }

        foreach ($this->embeddedSensitiveLiterals() as $sensitive) {
            if ($sensitive !== '' && str_contains($encoded, $sensitive)) {
                return true;
            }
        }

        return $this->containsEmbeddedPiiOrToken($value);
    }

    /** Remove global secrets and embedded email or phone values from a safe diagnostic string. */
    public function sanitizeDiagnostic(string $message): string
    {
        return $this->redactEmbeddedSensitiveText($message);
    }

    /** @return list<array{fake_value: int|string|float, fake_type: string, paths: list<string>}> */
    public function idMappingSummary(): array
    {
        $summary = [];
        foreach ($this->idMappings as $mapping) {
            $summary[] = [
                'fake_value' => $mapping['fake'],
                'fake_type' => get_debug_type($mapping['fake']),
                'paths' => array_values(array_unique($mapping['paths'])),
            ];
        }

        return $summary;
    }

    /** @return list<array{fake_value: string, paths: list<string>}> */
    public function referenceMappingSummary(): array
    {
        $summary = [];
        foreach ($this->referenceMappings as $mapping) {
            $summary[] = [
                'fake_value' => $mapping['fake'],
                'paths' => array_values(array_unique($mapping['paths'])),
            ];
        }

        return $summary;
    }

    private function collectGlobalLeakGuardValues(mixed $value, string $key = '', string $path = '$'): void
    {
        if ($value instanceof stdClass) {
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                $this->collectGlobalLeakGuardValues($childValue, $name, $path . '.' . $name);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                $childPath = array_is_list($value) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                $this->collectGlobalLeakGuardValues($childValue, $name, $childPath);
            }

            return;
        }

        if (is_string($value)) {
            if ($this->isSensitiveKey($key) || $this->isContextSensitiveKey($key, $path)) {
                $this->rememberEmbeddedSensitiveValue($value);
            }

            if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $this->collectUrlSecrets($value);
            }

            if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $emails) > 0) {
                foreach ($emails[0] as $email) {
                    $this->rememberEmbeddedSensitiveValue($email);
                }
            }

            if (preg_match_all('/(?<!\d)(?:\+?20)?01[0-2,5]\d{8}(?!\d)/', $value, $phones) > 0) {
                foreach ($phones[0] as $phone) {
                    if ($phone !== $this->publicWalletTestMsisdn) {
                        $this->rememberEmbeddedSensitiveValue($phone);
                    }
                }
            }
            if ($this->isIdKey($key, $value) || $this->isPrivateReferenceKey($key)) {
                $this->reservedIdValues['string:' . $value] = true;
                if (ctype_digit($value)) {
                    $this->reservedNumericIds['numeric:' . $value] = true;
                }
            }
        } elseif (is_int($value) || is_float($value)) {
            if ($this->isIdKey($key, $value) || $this->isPrivateReferenceKey($key)) {
                $this->reservedIdValues[get_debug_type($value) . ':' . (string)$value] = true;
                if (is_int($value) || floor($value) === $value) {
                    $this->reservedNumericIds['numeric:' . (string)(int)$value] = true;
                }
            }

            if ($this->isSensitiveKey($key)) {
                $this->rememberEmbeddedSensitiveValue((string)$value);
            }
        }
    }

    private function rememberEmbeddedSensitiveValue(string $value): void
    {
        if ($value !== '' && $value !== $this->publicWalletTestMsisdn) {
            $this->embeddedSensitiveValues['secret:' . $value] = true;
        }
    }

    /** @return list<string> */
    private function embeddedSensitiveLiterals(): array
    {
        return array_map(
            static fn(string $key): string => substr($key, strlen('secret:')),
            array_keys($this->embeddedSensitiveValues),
        );
    }

    private function collectUrlSecrets(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return;
        }

        foreach (explode('&', (string)($parts['query'] ?? '')) as $pair) {
            $bits = explode('=', $pair, 2);
            $name = rawurldecode($bits[0]);
            if (isset($bits[1]) && $this->isSensitiveKey($name)) {
                $this->rememberEmbeddedSensitiveValue(rawurldecode($bits[1]));
            }
        }

        foreach (explode('/', (string)($parts['path'] ?? '')) as $segment) {
            $decoded = rawurldecode($segment);
            if ($this->looksLikeSecretToken($decoded)) {
                $this->rememberEmbeddedSensitiveValue($decoded);
            }
        }
    }

    private function redactSubtree(mixed $value, string $key, string $path): mixed
    {
        if ($value instanceof stdClass) {
            $safe = new stdClass();
            foreach ($value as $childKey => $child) {
                $name = (string)$childKey;
                $childPath = $path . '.' . $name;
                $safe->{$name} = $this->isSemanticKey($name)
                    || $this->isIdKey($name, $child)
                    || $this->isPrivateReferenceKey($name)
                    || $this->isPiiKey($name, $childPath)
                    || $this->isUrlKey($name)
                    ? $this->sanitize($child, $name, $childPath)
                    : $this->redactSubtree($child, $name, $childPath);
            }

            return $safe;
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $child) {
                $name = (string)$childKey;
                $childPath = array_is_list($value) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                $safe[$childKey] = $this->isSemanticKey($name)
                    || $this->isIdKey($name, $child)
                    || $this->isPrivateReferenceKey($name)
                    || $this->isPiiKey($name, $childPath)
                    || $this->isUrlKey($name)
                    ? $this->sanitize($child, $name, $childPath)
                    : $this->redactSubtree($child, $name, $childPath);
            }

            return $safe;
        }

        if (is_string($value)) {
            return '<REDACTED_SECRET>';
        }

        if (is_int($value)) {
            return $this->redactedNumber($value);
        }

        if (is_float($value)) {
            return $this->redactedNumber($value);
        }

        return $value;
    }

    private function mapId(int|float|string $value, string $path): int|float|string
    {
        $numeric = is_int($value) || is_float($value) || ctype_digit($value);
        $key = ($numeric ? 'numeric:' : get_debug_type($value) . ':') . (string)$value;
        if (!isset($this->idMappings[$key])) {
            $fake = $this->newFakeId($value);
            $this->idMappings[$key] = [
                'fake' => $fake,
                'paths' => [],
                'original_type' => get_debug_type($value),
            ];
            $this->usedFakeIds[get_debug_type($fake) . ':' . (string)$fake] = $key;
        }

        $this->idMappings[$key]['paths'][] = $path;
        $fake = $this->idMappings[$key]['fake'];
        if (is_int($value)) {
            return (int)$fake;
        }
        if (is_float($value)) {
            return (float)$fake;
        }
        if ($numeric) {
            return (string)$fake;
        }

        return $fake;
    }

    private function newFakeId(int|float|string $original): int|float|string
    {
        if (is_int($original) || is_float($original) || ctype_digit($original)) {
            for ($attempt = 0; $attempt < 100000; $attempt++) {
                $candidate = $this->nextId++;
                $candidateString = (string)$candidate;
                $reserved = false;
                foreach (array_keys($this->reservedNumericIds) as $reservedKey) {
                    $rawId = substr((string)$reservedKey, strlen('numeric:'));
                    if (strlen($rawId) >= 5 && (str_contains($candidateString, $rawId) || str_contains($rawId, $candidateString))) {
                        $reserved = true;
                        break;
                    }
                }

                if (!$reserved && !isset($this->usedFakeIds['integer:' . $candidateString])
                    && !isset($this->usedFakeIds['string:' . $candidateString])) {
                    return is_float($original) ? (float)$candidate : (is_string($original) ? $candidateString : $candidate);
                }
            }

            throw new \RuntimeException('Unable to allocate a collision-free sanitized ID.');
        }

        $candidate = 'PV-ID-' . str_pad((string)$this->nextId++, 6, '0', STR_PAD_LEFT);
        while (isset($this->usedFakeIds['string:' . $candidate]) || isset($this->reservedIdValues['string:' . $candidate])) {
            $candidate = 'PV-ID-' . str_pad((string)$this->nextId++, 6, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    private function mapReference(string $original, string $key, string $path): string
    {
        if (!isset($this->referenceMappings[$original])) {
            $sequence = count($this->referenceMappings) + 1;
            $fake = 'PV-REF-' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
            if (strtolower($key) === 'merchant_order_id') {
                $fake = 'provider-verify-' . $fake;
            }
            $this->referenceMappings[$original] = ['fake' => $fake, 'paths' => []];
        }

        $this->referenceMappings[$original]['paths'][] = $path;
        return $this->referenceMappings[$original]['fake'];
    }

    private function redactEmbeddedSensitiveText(string $value): string
    {
        $secrets = $this->embeddedSensitiveLiterals();
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '<REDACTED_SECRET>', $value);
            }
        }

        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '<SANITIZED_EMAIL>', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(?:\+?20)?01[0-2,5]\d{8}(?!\d)/', '<SANITIZED_PHONE>', $value) ?? $value;
        $value = preg_replace('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_.-]{8,}(?:\.[A-Za-z0-9_.-]*)?/', '<REDACTED_TOKEN>', $value) ?? $value;
        $value = preg_replace('/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{48,}(?![A-Za-z0-9_-])/', '<REDACTED_TOKEN>', $value) ?? $value;
        return $value;
    }

    private function hasSensitiveScalarValue(mixed $value): bool
    {
        return (is_string($value) && $value !== '') || is_int($value) || is_float($value);
    }

    private function isExpectedSensitiveScalarReplacement(
        string $key,
        string $path,
        string|int|float $raw,
        mixed $safe,
    ): bool {
        if ($this->isPhoneCollectionElement($path)) {
            if (is_string($raw)) {
                return $safe === '+20000000000';
            }

            $expected = is_float($raw) ? 20000000000.0 : 20000000000;
            return $safe === $expected;
        }

        if ($this->isSensitiveKey($key) || $this->isContextSensitiveKey($key, $path)) {
            $expected = is_string($raw) ? '<REDACTED_SECRET>' : $this->redactedNumber($raw);
            return $safe === $expected;
        }

        if (is_string($raw)) {
            if ($this->isEmailKey($key)) {
                return $safe === 'customer@example.test';
            }
            if ($this->isPhoneKey($key)) {
                return $safe === '+20000000000';
            }
            if ($this->isNameKey($key, $path)) {
                return $safe === '<SANITIZED_NAME>';
            }
            if ($this->isAddressKey($key, $path)) {
                return $safe === '<SANITIZED_ADDRESS>';
            }
            if ($this->isIpKey($key)) {
                return $safe === '192.0.2.1';
            }
        }

        if ($this->isPhoneKey($key)) {
            $expected = is_float($raw) ? 20000000000.0 : 20000000000;
            return $safe === $expected;
        }

        return $safe === $this->redactedNumber($raw);
    }

    private function isContextualPiiKey(string $key, string $path): bool
    {
        return $this->isPhoneCollectionElement($path)
            || $this->isNameKey($key, $path)
            || $this->isAddressKey($key, $path)
            || $this->isIpKey($key);
    }

    private function isContextSensitiveKey(string $key, string $path): bool
    {
        return strtolower($key) === 'key'
            && preg_match('/(?:^|\.)payment_keys\[\d+\]\.key$/', $path) === 1;
    }

    private function redactedNumber(int|float $value): int|float
    {
        if (is_float($value)) {
            return $value === 1.0 ? 2.0 : 1.0;
        }

        return $value === 1 ? 2 : 1;
    }

    private function containsEmbeddedPiiOrToken(mixed $value, string $key = '', string $path = '$'): bool
    {
        if ($value instanceof stdClass) {
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                if ($this->containsEmbeddedPiiOrToken($childValue, $name, $path . '.' . $name)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $name = (string)$childKey;
                $childPath = array_is_list($value) ? $path . '[' . $childKey . ']' : $path . '.' . $name;
                if ($this->containsEmbeddedPiiOrToken($childValue, $name, $childPath)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        if ($this->isContextSensitiveKey($key, $path) && $value !== '' && $value !== '<REDACTED_SECRET>') {
            return true;
        }

        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $emails) > 0) {
            foreach ($emails[0] as $email) {
                if ($email !== 'customer@example.test') {
                    return true;
                }
            }
        }
        if (preg_match_all('/(?<!\d)(?:\+?20)?01[0-2,5]\d{8}(?!\d)/', $value, $phones) === false) {
            return false;
        }
        foreach ($phones[0] as $phone) {
            if (!($this->isWalletTestPath($path) && $phone === $this->publicWalletTestMsisdn)) {
                return true;
            }
        }

        if (!$this->isHashMetadataKey($key)
            && (preg_match('/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_.-]{8,}(?:\.[A-Za-z0-9_.-]*)?/', $value) === 1
                || preg_match('/(?<![A-Za-z0-9])[A-Za-z0-9]{48,}(?![A-Za-z0-9])/', $value) === 1)) {
            return true;
        }

        return false;
    }

    private function isHashMetadataKey(string $key): bool
    {
        return preg_match('/(^|_)(sha-?256|hash|checksum)(_|$)/i', $key) === 1;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, ['token_type', 'token_expires_at'], true)) {
            return false;
        }

        return (bool)preg_match(
            '/(^|_)(token|api_key|secret|client_secret|hmac|password|nonce|signature|authorization|credential|otp|mpin|pin|pan|card_number|cvv|cvc|tax_id|vat_number|national_id|commercial_register|account_number)(_|$)/i',
            $key,
        );
    }

    private function isIdKey(string $key, mixed $value): bool
    {
        if (strtolower($key) === 'merchant_order_id') {
            return false;
        }

        if (preg_match('/(^|_)(id|pk)$/i', $key)) {
            return is_string($value) || is_int($value) || is_float($value);
        }

        return in_array(strtolower($key), ['owner', 'order'], true)
            && (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value)));
    }

    private function isIntentionIntegrationId(string $path): bool
    {
        return preg_match('/\.request\.payment_methods\[\d+\]$/', $path) === 1;
    }

    private function isPrivateReferenceKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'special_reference',
            'merchant_order_id',
            'other_endpoint_reference',
            'mer_txn_ref',
            'order_info',
            'bill_reference',
            'upg_qrcode_ref',
        ], true);
    }

    private function usesNumericIdMappingForPrivateReference(string $key, string $value): bool
    {
        return ctype_digit($value)
            && in_array(strtolower($key), self::NUMERIC_PRIVATE_REFERENCE_ID_KEYS, true);
    }

    private function isUrlKey(string $key): bool
    {
        return (bool)preg_match('/(^|_)(redirect|iframe_redirection|return|callback|notification|order|checkout)_?url$/i', $key);
    }

    private function isEmailKey(string $key): bool
    {
        return in_array(strtolower($key), ['email', 'email_address'], true)
            || (bool)preg_match('/(^|_)(email|email_address)$/i', $key);
    }

    private function isPhoneKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'phone', 'phone_number', 'mobile', 'mobile_number', 'msisdn', 'wallet_msisdn', 'debit_mobile_wallet_no',
        ], true) || (bool)preg_match('/(^|_)(phone|mobile|msisdn)$/i', $key);
    }

    /** Identify a scalar whose direct parent is the explicit phones list. */
    private function isPhoneCollectionElement(string $path): bool
    {
        return preg_match('/\.phones\[\d+\]$/i', $path) === 1;
    }

    private function isNameKey(string $key, string $path): bool
    {
        $lower = strtolower($key);
        if (in_array($lower, [
            'first_name', 'last_name', 'full_name', 'customer_name', 'owner_name', 'username', 'sms_sender_name',
            'company', 'company_name', 'merchant_name', 'business', 'business_name', 'brand_name', 'legal_name',
        ], true)) {
            return true;
        }

        return in_array($lower, ['name', 'company', 'business'], true)
            && $this->hasContext($path, [
                'customer', 'merchant', 'merchant_data', 'profile', 'company', 'business', 'billing_data', 'shipping_data',
            ]);
    }

    private function isAddressKey(string $key, string $path): bool
    {
        return in_array(strtolower($key), [
            'street', 'building', 'floor', 'apartment', 'postal_code', 'zip', 'city', 'state',
            'address', 'address_line_1', 'address_line_2', 'extra_description',
        ], true) && $this->hasContext($path, [
            'customer', 'merchant', 'merchant_data', 'profile', 'company', 'business', 'billing_data', 'shipping_data',
        ]);
    }

    private function isIpKey(string $key): bool
    {
        return (bool)preg_match('/(^|_)(ip|ip_address)$/i', $key);
    }

    private function isPiiKey(string $key, string $path): bool
    {
        return $this->isEmailKey($key) || $this->isPhoneKey($key)
            || $this->isNameKey($key, $path) || $this->isAddressKey($key, $path) || $this->isIpKey($key);
    }

    private function isWalletTestPath(string $path): bool
    {
        return str_ends_with(strtolower($path), '.source.identifier');
    }

    private function isSemanticKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'currency', 'payment_status', 'status', 'payment_method', 'api_source', 'shipping_method',
            'type', 'sub_type', 'subtype', 'klass', 'message', 'txn_response_code', 'wallet_issuer',
            'gateway_source', 'method', 'response_code', 'error_code', 'error_message', 'error_occured',
            'success', 'pending', 'is_live', 'is_auth', 'is_capture', 'is_standalone_payment', 'is_voided',
            'is_refunded', 'is_3d_secure', 'has_parent_transaction', 'is_void', 'is_refund', 'is_hidden',
            'is_captured', 'is_settled', 'bill_balanced', 'is_bill', 'is_payment_locked', 'is_return',
            'is_cancel', 'is_returned', 'is_canceled', 'delivery_needed', 'notify_user_with_email',
            'lock_order_when_paid', 'single_payment_attempt', 'amount', 'amount_cents', 'amount_cents_int',
            'original_amount', 'paid_amount_cents', 'refunded_amount_cents', 'refunded_amount_cents_int',
            'captured_amount', 'captured_amount_int', 'settlement_amount_cents_int', 'merchant_commission',
            'accept_fees', 'accept_fees_cents_int', 'vat_cents_int', 'vat_cents_float', 'commission_fees',
            'delivery_fees_cents', 'delivery_vat_cents', 'quantity', 'exp', 'iat', 'nbf', 'created_at',
            'updated_at', 'timestamp', 'expires_at', 'issued_at', 'created', 'updated',
        ], true);
    }

    private function hasContext(string $path, array $contexts): bool
    {
        $path = strtolower($path);
        foreach ($contexts as $context) {
            if (str_contains($path, strtolower($context))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSecretToken(string $value): bool
    {
        return strlen($value) >= 40
            || (bool)preg_match('/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_.-]{8,}(?:\.[A-Za-z0-9_.-]*)?$/', $value)
            || (bool)preg_match('/^[a-f0-9-]{24,}$/i', $value);
    }
}
