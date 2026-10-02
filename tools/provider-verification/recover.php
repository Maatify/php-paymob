<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Maatify\Paymob\ProviderVerification\Support\RetainedRunRecovery;

$repositoryRoot = dirname(__DIR__, 2);
$sourceDirectory = $argc === 3 ? $argv[1] : '';
$scenario = $argc === 3 ? $argv[2] : '';
$recovery = RetainedRunRecovery::recover($repositoryRoot, $sourceDirectory, $scenario);
$artifact = $recovery['artifact'] ?? $recovery['failure_artifact'] ?? null;
$summary = [
    'result' => $recovery['result'],
    'scenario' => $recovery['scenario'],
    'source_run' => $recovery['source_run'],
    'exchange_count' => $recovery['exchange_count'],
    'recovered_stages' => $recovery['recovered_stages'],
    'recovery_artifact_path' => $artifact['path'] ?? null,
    'recovery_artifact_bytes' => $artifact['bytes'] ?? null,
    'recovery_artifact_sha256' => $artifact['sha256'] ?? null,
    'source_raw_retained' => $recovery['source_raw_retained'],
];

fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL);
exit($recovery['result'] === 'PASS' ? 0 : 1);
