<?php

declare(strict_types=1);

namespace Maatify\Paymob\Tests\System\ReturnUrl;

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Callback\DTO\ReturnUrlResponseDTO;
use Maatify\Paymob\Callback\Service\ReturnUrlHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReturnUrlHandlerSystemTest extends TestCase
{
    private const HMAC_SECRET = 'synthetic-return-url-secret';

    private const EXPECTED_CANONICAL = '500002024-06-25T15:16:25.910710+04:00EGPfalsefalse3160042936truefalsefalsefalsetruefalse378804211false2346MasterCardcardtrue';

    private const EXPECTED_PENDING_CANONICAL = '500002024-06-25T15:16:25.910710+04:00EGPfalsefalse3160042936truefalsefalsefalsetruefalse378804211true2346MasterCardcardfalse';

    private const RAW_QUERY = 'amount_cents=50000'
        . '&created_at=2024-06-25T15%3A16%3A25.910710%2B04%3A00'
        . '&currency=EGP&error_occured=false&has_parent_transaction=false'
        . '&id=316004&integration_id=2936&is_3d_secure=true&is_auth=false'
        . '&is_capture=false&is_refunded=false&is_standalone_payment=true'
        . '&is_voided=false&order=378804&owner=211&pending=false'
        . '&source_data.pan=2346&source_data.sub_type=MasterCard'
        . '&source_data.type=card&success=true&data.message=Approved';

    public function testValidOrderOnlyPhpNormalizedResponseCallbackReturnsMappedDto(): void
    {
        $query = $this->validQuery();

        foreach (['source_data_pan', 'source_data_sub_type', 'source_data_type', 'data_message'] as $key) {
            self::assertArrayHasKey($key, $query);
        }

        self::assertArrayNotHasKey('order.id', $query);
        self::assertArrayNotHasKey('order_id', $query);
        self::assertArrayNotHasKey('source_data.pan', $query);
        self::assertArrayNotHasKey('data.message', $query);
        self::assertSame('378804', $query['order']);
        self::assertSame('2346', $query['source_data_pan']);
        self::assertSame('MasterCard', $query['source_data_sub_type']);
        self::assertSame('card', $query['source_data_type']);

        $result = $this->handler()->parse($query);

        self::assertInstanceOf(ReturnUrlResponseDTO::class, $result);
        self::assertSame(316004, $result->transactionId);
        self::assertSame(378804, $result->orderId);
        self::assertSame(50000, $result->amountCents);
        self::assertSame('EGP', $result->currency);
        self::assertTrue($result->success);
        self::assertFalse($result->pending);
        self::assertSame('Approved', $result->message);
        self::assertSame($query['hmac'], $result->hmac);
    }

    public function testOrderIdOnlyUsesTheSameLiteralCanonicalHmacAsOrderOnly(): void
    {
        $orderOnlyQuery = $this->validQuery();
        $rawQuery = str_replace('&order=378804&', '&order_id=378804&', self::RAW_QUERY)
            . '&hmac=' . hash_hmac('sha512', self::EXPECTED_CANONICAL, self::HMAC_SECRET);
        parse_str($rawQuery, $orderIdQuery);

        self::assertArrayNotHasKey('order', $orderIdQuery);
        self::assertSame('378804', $orderIdQuery['order_id']);
        self::assertSame($orderOnlyQuery['hmac'], $orderIdQuery['hmac']);

        $orderOnlyResult = $this->handler()->parse($orderOnlyQuery);
        $orderIdResult = $this->handler()->parse($orderIdQuery);

        self::assertSame(378804, $orderOnlyResult->orderId);
        self::assertSame(378804, $orderIdResult->orderId);
        self::assertSame($orderOnlyResult->hmac, $orderIdResult->hmac);
    }

    public function testBothOrderKeysWithEqualValuesAreAccepted(): void
    {
        $query = $this->validQuery();
        $query['order_id'] = '378804';

        $result = $this->handler()->parse($query);

        self::assertSame(378804, $result->orderId);
        self::assertSame($query['hmac'], $result->hmac);
    }

    public function testBothOrderKeysWithDifferentValuesAreRejected(): void
    {
        $query = $this->validQuery();
        $query['order_id'] = '999999';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conflicting order query values');

        $this->handler()->parse($query);
    }

    public function testNeitherOrderKeyIsRejected(): void
    {
        $query = $this->validQuery();
        unset($query['order']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing order query value');

        $this->handler()->parse($query);
    }

    #[DataProvider('invalidOrderValues')]
    public function testNonStringOrderValueIsRejected(string $key, mixed $value): void
    {
        $query = $this->validQuery();
        if ($key === 'order_id') {
            unset($query['order']);
        }
        $query[$key] = $value;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid {$key} query value");

        $this->handler()->parse($query);
    }

    public static function invalidOrderValues(): iterable
    {
        foreach (['order', 'order_id'] as $key) {
            yield "{$key} as integer" => [$key, 378804];
            yield "{$key} as array" => [$key, ['378804']];
            yield "{$key} as null" => [$key, null];
        }
    }

    #[DataProvider('orderKeys')]
    public function testEmptyCanonicalOrderValueIsRejected(string $key): void
    {
        $query = $this->validQuery();
        if ($key === 'order_id') {
            unset($query['order']);
        }
        $query[$key] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid {$key} query value");

        $this->handler()->parse($query);
    }

    public static function orderKeys(): iterable
    {
        yield 'order' => ['order'];
        yield 'order_id' => ['order_id'];
    }

    public function testDataMessageCanChangeWithoutChangingTheValidHmac(): void
    {
        $query = $this->validQuery();
        $originalHmac = $query['hmac'];
        $query['data_message'] = 'Updated advisory message';

        $result = $this->handler()->parse($query);

        self::assertSame($originalHmac, $result->hmac);
        self::assertSame('Updated advisory message', $result->message);
    }

    public function testValidPendingResponseMapsBothBooleanValues(): void
    {
        $query = $this->validQuery();
        $query['success'] = 'false';
        $query['pending'] = 'true';
        $query['hmac'] = hash_hmac('sha512', self::EXPECTED_PENDING_CANONICAL, self::HMAC_SECRET);

        $result = $this->handler()->parse($query);

        self::assertFalse($result->success);
        self::assertTrue($result->pending);
    }

    public function testCorrectlySignedNonCanonicalNumericValuesAreRejected(): void
    {
        foreach (['id' => ['01', '0', '-1', '+1', ' 1', '1.0', '1e2', '999999999999999999999999999999'],
            'order' => ['01', '0', '-1', '+1', ' 1', '1.0', '1e2'],
            'amount_cents' => ['01', '0', '-1', '+1', ' 1', '1.0', '1e2']] as $field => $values) {
            foreach ($values as $value) {
                $query = $this->validQuery();
                $query[$field] = $value;
                $query['hmac'] = $this->signQuery($query);
                $this->assertRejected($query);
            }
        }
    }

    private function signQuery(array $query): string
    {
        $fields = ['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
            'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order', 'owner', 'pending', 'source_data_pan', 'source_data_sub_type',
            'source_data_type', 'success'];
        $values = array_map(static fn(string $field): string => (string)($field === 'order'
            ? ($query['order'] ?? $query['order_id']) : $query[$field]), $fields);
        return hash_hmac('sha512', implode('', $values), self::HMAC_SECRET);
    }

    #[DataProvider('distinctSignedFields')]
    public function testTamperingWithFlatOrderOrNormalizedSourceDataIsRejected(string $field): void
    {
        $query = $this->validQuery();
        $query[$field] = 'tampered';

        $this->assertRejected($query);
    }

    public static function distinctSignedFields(): iterable
    {
        yield 'flat order' => ['order'];
        yield 'normalized source pan' => ['source_data_pan'];
        yield 'normalized source subtype' => ['source_data_sub_type'];
        yield 'normalized source type' => ['source_data_type'];
    }

    public function testFlatOrderCannotBeReplacedByDottedOrderId(): void
    {
        $query = $this->validQuery();
        unset($query['order']);
        $query['order.id'] = '378804';

        $this->assertRejected($query);
    }

    public function testNormalizedSourcePanCannotBeReplacedByDottedLookup(): void
    {
        $query = $this->validQuery();
        unset($query['source_data_pan']);
        $query['source_data.pan'] = '2346';

        $this->assertRejected($query);
    }

    public function testWrongHmacIsRejected(): void
    {
        $query = $this->validQuery();
        $query['hmac'] = str_repeat('0', 128);

        $this->assertRejected($query);
    }

    public function testMissingHmacIsRejected(): void
    {
        $query = $this->validQuery();
        unset($query['hmac']);

        $this->assertRejected($query);
    }

    public function testNonStringHmacIsRejected(): void
    {
        $query = $this->validQuery();
        $query['hmac'] = ['invalid'];

        $this->assertRejected($query);
    }

    public function testEmptyHmacIsRejected(): void
    {
        $query = $this->validQuery();
        $query['hmac'] = '';

        $this->assertRejected($query);
    }

    #[DataProvider('signedFields')]
    public function testMissingSignedFieldIsRejected(string $field): void
    {
        $query = $this->validQuery();
        unset($query[$field]);

        $this->assertRejected($query);
    }

    #[DataProvider('signedFields')]
    public function testNonStringSignedFieldIsRejected(string $field): void
    {
        $query = $this->validQuery();
        $query[$field] = ['invalid'];

        $this->assertRejected($query);
    }

    public static function signedFields(): iterable
    {
        foreach ([
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order',
            'owner',
            'pending',
            'source_data_pan',
            'source_data_sub_type',
            'source_data_type',
            'success',
        ] as $field) {
            yield $field => [$field];
        }
    }

    public function testEmptyConfiguredHmacSecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HMAC secret must not be empty');

        $this->handler('')->parse($this->validQuery());
    }

    #[DataProvider('invalidBooleanValues')]
    public function testInvalidSuccessOrPendingValueIsRejected(string $field, string $value): void
    {
        $query = $this->validQuery();
        $query[$field] = $value;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid {$field} query value");

        $this->handler()->parse($query);
    }

    public static function invalidBooleanValues(): iterable
    {
        foreach (['1', '0', 'yes', 'no', 'TRUE', 'invalid', ''] as $value) {
            yield "success={$value}" => ['success', $value];
            yield "pending={$value}" => ['pending', $value];
        }
    }

    private function validQuery(): array
    {
        $rawQuery = self::RAW_QUERY
            . '&hmac=' . hash_hmac('sha512', self::EXPECTED_CANONICAL, self::HMAC_SECRET);
        parse_str($rawQuery, $query);

        return $query;
    }

    private function handler(string $hmacSecret = self::HMAC_SECRET): ReturnUrlHandler
    {
        return new ReturnUrlHandler(new PaymobConfig(
            apiKey: 'synthetic-api-key',
            integrationIdCard: 1,
            integrationIdKiosk: 2,
            integrationIdWallet: 3,
            baseUrl: 'https://example.invalid',
            hmacSecret: $hmacSecret,
        ));
    }

    private function assertRejected(array $query): void
    {
        $this->expectException(RuntimeException::class);
        $this->handler()->parse($query);
    }
}
