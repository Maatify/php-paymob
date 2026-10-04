<?php

declare(strict_types=1);

namespace Maatify\Paymob\Tests\System\Webhook;

use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\DTO\Webhook\WebhookPayloadDTO;
use Maatify\Paymob\Exception\WebhookException;
use Maatify\Paymob\Webhook\WebhookValidator;
use PHPUnit\Framework\TestCase;

final class WebhookValidatorSystemTest extends TestCase
{
    private const HMAC_SECRET = 'synthetic-webhook-secret';

    private const EXPECTED_CANONICAL = '1000002024-06-13T11:33:44.592345EGPfalsefalse1920364654097558truefalsefalsefalsetruefalse217503754302852false2346MasterCardcardtrue';

    public function testValidNormalizedTransactionCallbackReturnsParsedDto(): void
    {
        $result = $this->validator()->validate($this->validPayload());

        self::assertInstanceOf(WebhookPayloadDTO::class, $result);
        self::assertSame(192036465, $result->transactionId);
        self::assertSame(217503754, $result->orderId);
        self::assertSame(100000, $result->amountCents);
        self::assertSame('EGP', $result->currency);
        self::assertTrue($result->success);
        self::assertFalse($result->pending);
        self::assertSame('card', $result->paymentMethod);
        self::assertSame('MasterCard', $result->subType);
        self::assertSame('2346', $result->maskedPan);
    }

    public function testTamperedSignedValueIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['obj']['amount_cents'] = 100001;

        $this->assertRejected($payload);
    }

    public function testWrongHmacIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['hmac'] = str_repeat('0', 128);

        $this->assertRejected($payload);
    }

    public function testMissingHmacIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['hmac']);

        $this->assertRejected($payload);
    }

    public function testNonStringHmacIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['hmac'] = 123;

        $this->assertRejected($payload);
    }

    public function testEmptyHmacIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['hmac'] = '';

        $this->assertRejected($payload);
    }

    public function testMissingRequiredTopLevelSignedLeafIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['obj']['owner']);

        $this->assertRejected($payload);
    }

    public function testMissingNestedOrderIdIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['obj']['order']['id']);

        $this->assertRejected($payload);
    }

    public function testMissingSourceDataSignedLeafIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['obj']['source_data']['pan']);

        $this->assertRejected($payload);
    }

    public function testMalformedTransactionObjectIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['obj'] = 'not-an-object';

        $this->assertRejected($payload);
    }

    public function testNonTransactionCallbackIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['type'] = 'TOKEN';

        $this->assertRejected($payload);
    }

    public function testUnsupportedSignedLeafShapeIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['obj']['source_data']['pan'] = ['2346'];

        $this->assertRejected($payload);
    }

    private function assertRejected(array $payload): void
    {
        $this->expectException(WebhookException::class);
        $this->validator()->validate($payload);
    }

    private function validator(): WebhookValidator
    {
        return new WebhookValidator(new PaymobConfigDTO(
            apiKey: 'synthetic-api-key',
            integrationIdCard: 1,
            integrationIdKiosk: 2,
            integrationIdWallet: 3,
            baseUrl: 'https://example.invalid',
            hmacSecret: self::HMAC_SECRET
        ));
    }

    private function validPayload(): array
    {
        return [
            'type' => 'TRANSACTION',
            'obj' => [
                'amount_cents' => 100000,
                'created_at' => '2024-06-13T11:33:44.592345',
                'currency' => 'EGP',
                'error_occured' => false,
                'has_parent_transaction' => false,
                'id' => 192036465,
                'integration_id' => 4097558,
                'is_3d_secure' => true,
                'is_auth' => false,
                'is_capture' => false,
                'is_refunded' => false,
                'is_standalone_payment' => true,
                'is_voided' => false,
                'order' => ['id' => 217503754],
                'owner' => 302852,
                'pending' => false,
                'source_data' => [
                    'pan' => '2346',
                    'sub_type' => 'MasterCard',
                    'type' => 'card',
                ],
                'success' => true,
            ],
            'hmac' => hash_hmac('sha512', self::EXPECTED_CANONICAL, self::HMAC_SECRET),
        ];
    }
}
