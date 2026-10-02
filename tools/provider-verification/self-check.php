<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Maatify\Paymob\ProviderVerification\Support\SemanticSanitizer;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $rawJson = <<<'JSON'
{
  "amount": 15000,
  "amount_cents": 15000,
  "currency": "EGP",
  "payment_status": "UNPAID",
  "pending": true,
  "success": false,
  "optional": null,
  "source_data": {"type": "wallet", "sub_type": "WALLET"},
  "data": {"klass": "Wallet", "message": "Transaction Created Successfully", "txn_response_code": "APPROVED"},
  "created_at": "2026-10-02T12:00:00.000000Z",
  "order": {"id": 123456, "payment_status": "UNPAID"},
  "shipping_data": {"order_id": 123456, "order": 123456, "integration_id": 73486},
  "payment_key_claims": {"order_id": 123456, "integration_id": 73486},
  "transaction": {"id": 887766, "source_id": 998877, "integration_id": 73486},
  "merchant_order_id": "private-merchant-reference-20261002",
  "customer": {
    "first_name": "Real Customer Name",
    "last_name": "Private Surname",
    "email": "person@example.org",
    "phone_number": "01012345678",
    "address": "Private Street 12"
  },
  "source": {"identifier": "01010101010", "subtype": "WALLET"},
  "api_key": "configured-api-key-example",
  "hmac_secret": "configured-hmac-secret-example",
  "token": "synthetic-provider-token-placeholder",
  "token_context": {"type": "wallet", "token": "nested-sensitive-token", "message": "Transaction Created Successfully"},
  "redirect_url": "https://accept.paymob.com/redirect/123456?token=synthetic-provider-token-placeholder&order_id=123456&merchant_order_id=private-merchant-reference-20261002&status=UNPAID",
  "details": {"values": [1, 1.5, true, false, null, {"enabled": true}]}
}
JSON;

    $raw = json_decode($rawJson);
    verify($raw instanceof stdClass, 'Synthetic source JSON did not decode to an object.');
    $sanitizer = new SemanticSanitizer(['configured-api-key-example', 'configured-hmac-secret-example'], '01010101010');
    $sanitizer->prime([$raw]);
    $safe = $sanitizer->sanitize($raw);

    verify($sanitizer->sameShape($raw, $safe), 'Sanitization changed a JSON container or scalar type.');
    verify($safe->amount === 15000 && is_int($safe->amount), 'Integer amount was not preserved.');
    verify($safe->amount_cents === 15000 && is_int($safe->amount_cents), 'Integer amount_cents was not preserved.');
    verify($safe->pending === true && $safe->success === false && $safe->optional === null, 'Boolean or null values changed.');
    verify($safe->details->values[1] === 1.5 && is_float($safe->details->values[1]), 'Float value was not preserved.');
    verify($safe->payment_status === 'UNPAID', 'payment_status semantic value changed.');
    verify($safe->source_data->type === 'wallet' && $safe->source_data->sub_type === 'WALLET', 'Wallet source semantics changed.');
    verify($safe->data->message === 'Transaction Created Successfully', 'Provider message semantic value changed.');
    verify($safe->created_at === '2026-10-02T12:00:00.000000Z', 'Timestamp value changed.');
    echo "PASS scalar types and nested containers\n";
    echo "PASS semantic preservation\n";

    verify($safe->customer->email === 'customer@example.test', 'Email was not replaced.');
    verify($safe->customer->phone_number === '+20000000000', 'Private phone was not replaced.');
    verify($safe->customer->first_name === '<SANITIZED_NAME>', 'Customer name was not replaced.');
    verify($safe->customer->address === '<SANITIZED_ADDRESS>', 'Customer address was not replaced.');
    verify($safe->source->identifier === '01010101010', 'Public wallet test input did not remain recognizable.');
    echo "PASS PII redaction and public wallet test input handling\n";

    verify($safe->token !== $raw->token, 'Provider token was not replaced.');
    verify($safe->token_context->type === 'wallet', 'Nested token metadata semantic type changed.');
    verify($safe->token_context->message === 'Transaction Created Successfully', 'Nested token metadata message changed.');
    verify($safe->token_context->token !== $raw->token_context->token, 'Nested provider token was not replaced.');
    verify($safe->api_key !== 'configured-api-key-example', 'Configured API key literal was not replaced.');
    verify($safe->hmac_secret !== 'configured-hmac-secret-example', 'Configured HMAC secret literal was not replaced.');
    verify(!$sanitizer->containsSensitiveValues($safe), 'A configured secret or detected sensitive value remains.');
    $safeJson = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(!str_contains($safeJson, $raw->token), 'Original provider token appears in sanitized JSON.');
    verify(!str_contains($safeJson, $raw->token_context->token), 'Nested provider token appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'configured-api-key-example'), 'Configured API key appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'configured-hmac-secret-example'), 'Configured HMAC secret appears in sanitized JSON.');
    verify(!str_contains($safeJson, 'person@example.org'), 'Original email appears in sanitized JSON.');
    verify(!str_contains($safeJson, '01012345678'), 'Original private phone appears in sanitized JSON.');
    echo "PASS secret redaction and leak guard\n";

    $safeUrl = $safe->redirect_url;
    $safeUrlParts = parse_url($safeUrl);
    $rawUrlParts = parse_url($raw->redirect_url);
    verify(is_array($safeUrlParts) && is_array($rawUrlParts), 'Sanitized URL is invalid.');
    verify($safeUrlParts['scheme'] === $rawUrlParts['scheme'], 'URL scheme changed.');
    verify($safeUrlParts['path'] !== $rawUrlParts['path'], 'Account-specific URL path ID was not replaced.');
    parse_str((string)$safeUrlParts['query'], $safeQuery);
    parse_str((string)$rawUrlParts['query'], $rawQuery);
    verify(array_keys($safeQuery) === array_keys($rawQuery), 'URL query key names or structure changed.');
    verify($safeQuery['token'] !== $rawQuery['token'], 'URL secret token was not replaced.');
    verify($safeQuery['order_id'] === (string)$safe->order->id, 'URL order ID does not match its response ID mapping.');
    verify($safeQuery['merchant_order_id'] === $safe->merchant_order_id, 'URL merchant reference does not match its response reference mapping.');
    verify($safeQuery['status'] === 'UNPAID', 'URL semantic query value changed.');
    echo "PASS URL sanitization and query structure\n";

    verify($safe->order->id === $safe->shipping_data->order_id, 'Repeated order ID changed across fields.');
    verify($safe->order->id === $safe->shipping_data->order, 'Repeated order alias changed across fields.');
    verify($safe->order->id === $safe->payment_key_claims->order_id, 'Repeated order ID changed in claims.');
    verify($safe->shipping_data->integration_id === $safe->payment_key_claims->integration_id, 'Repeated integration ID changed in claims.');
    verify($safe->shipping_data->integration_id === $safe->transaction->integration_id, 'Repeated integration ID changed in transaction.');
    verify($safe->order->id !== $safe->transaction->id, 'Distinct order and transaction IDs collapsed.');
    verify($safe->transaction->id !== $safe->transaction->source_id, 'Distinct transaction and source IDs collapsed.');
    verify(is_int($safe->order->id) && $safe->order->id > 0, 'Sanitized numeric provider IDs are not positive integers.');
    echo "PASS repeated ID consistency and distinct-ID separation\n";

    $semanticDifferences = $sanitizer->semanticDifferences($raw, $safe);
    verify($semanticDifferences === [], 'Semantic-value verification reported a change.');
    $encoded = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    verify(is_string(json_decode($encoded)) || json_last_error() === JSON_ERROR_NONE, 'Sanitized output is not valid JSON.');
    echo "PASS sanitized JSON validity\n";
    echo "SELF-CHECK: PASS\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SELF-CHECK: FAIL (' . get_class($exception) . '): ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
