<?php

declare(strict_types=1);

namespace Maatify\Paymob\Tests\System\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Adapter\CurlApiClient;
use Maatify\Paymob\Adapter\GuzzleApiClient;
use Maatify\Paymob\Adapter\ResponseDecoder;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\Repository\FileTokenRepository;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Repository\Pdo\MySqlTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Callback\DTO\WebhookPayloadDTO;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\NotFoundException;
use Maatify\Paymob\Exception\DuplicateReferenceException;
use Maatify\Paymob\Exception\InvalidRequestException;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\PaymobExceptionInterface;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use Maatify\Paymob\Exception\RateLimitException;
use Maatify\Paymob\Exception\ServiceUnavailableException;
use Maatify\Paymob\Exception\TokenStorageException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Exception\ValidationException;
use Maatify\Paymob\Exception\WebhookException;
use Maatify\Paymob\Exception\ReturnUrlException;
use Maatify\Paymob\Factory\PaymobExceptionFactory;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\DTO\KioskFlowResultDTO;
use Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Payment\Service\WalletPaymentService;
use Maatify\Paymob\Payment\DTO\WalletFlowResultDTO;
use Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Transaction\DTO\TransactionResponseDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ErrorException;

final class RuntimeSecuritySystemTest extends TestCase
{
    public function testConfigValidationAndCanonicalBaseUrl(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3, 'https://accept.paymob.com/api/');
        self::assertSame('https://accept.paymob.com/api', $config->baseUrl);
        try { new PaymobConfig('', 'hmac', 1, 2, 3); self::fail('Expected empty key rejection.'); }
        catch (\InvalidArgumentException) {}
        try { new PaymobConfig('key', '', 1, 2, 3); self::fail('Expected empty HMAC rejection.'); }
        catch (\InvalidArgumentException) {}
        try { new PaymobConfig('key', 'hmac', 0, 2, 3); self::fail('Expected invalid ID rejection.'); }
        catch (\InvalidArgumentException) {}
        try { new PaymobConfig('key', 'hmac', 1, 2, 3, 'http://example.com'); self::fail('Expected non-HTTPS rejection.'); }
        catch (\InvalidArgumentException) {}
    }

    public function testAuthServiceRejectsObjectProfileWithPackageApiExceptionEvidence(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $response = ['token' => 'provider-token', 'profile' => (object)['id' => 7]];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        $auth = new AuthService($api, $config, new InMemoryTokenRepository(), new FixedTestClock(1000));

        try {
            $auth->getToken();
            self::fail('Malformed Auth profile shape must throw package ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
        }
    }

    public function testTokenScopeIsOpaqueDeterministicAndCredentialSpecific(): void
    {
        $first = new PaymobConfig('secret-key', 'hmac', 1, 2, 3);
        $same = new PaymobConfig('secret-key', 'hmac', 7, 8, 9);
        $otherKey = new PaymobConfig('other-key', 'hmac', 1, 2, 3);
        $otherBase = new PaymobConfig('secret-key', 'hmac', 1, 2, 3, 'https://other.example/api');
        $scope = TokenScope::fromConfig($first)->value();
        self::assertSame($scope, TokenScope::fromConfig($same)->value());
        self::assertNotSame($scope, TokenScope::fromConfig($otherKey)->value());
        self::assertNotSame($scope, TokenScope::fromConfig($otherBase)->value());
        self::assertStringNotContainsString('secret-key', $scope);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scope);
    }

    public function testDefaultAdapterAndOptionalMysqlGuardArePackageOwned(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $client = new ApiClient($config);
        $property = new \ReflectionProperty($client, 'client');
        self::assertInstanceOf(CurlApiClient::class, $property->getValue($client));
        try { new \Maatify\Paymob\Authentication\Repository\Pdo\MySqlTokenRepository(new \stdClass()); self::fail('Invalid MySQL selection must fail closed.'); }
        catch (OptionalCapabilityUnavailableException) {}
    }

    public function testApprovedPublicDtoSymbolsAreJsonSerializable(): void
    {
        foreach ([
            TokenResponseDTO::class,
            \Maatify\Paymob\Order\DTO\OrderItemDTO::class,
            \Maatify\Paymob\Order\DTO\OrderItemCollectionDTO::class,
            \Maatify\Paymob\Order\DTO\OrderResponseDTO::class,
            \Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO::class,
            \Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO::class,
            \Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO::class,
            \Maatify\Paymob\Payment\DTO\KioskFlowResultDTO::class,
            \Maatify\Paymob\Payment\DTO\WalletFlowResultDTO::class,
            \Maatify\Paymob\Transaction\DTO\TransactionResponseDTO::class,
            \Maatify\Paymob\Callback\DTO\WebhookPayloadDTO::class,
            \Maatify\Paymob\Callback\DTO\ReturnUrlResponseDTO::class,
        ] as $class) self::assertContains(\JsonSerializable::class, class_implements($class));
    }

    public function testCommandsSerializeOnlyIntentAndServicesComposeAuthToken(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $orderCommand = new CreateOrderCommand(
            100,
            CurrencyEnum::EGP,
            'order-ref',
            [new \Maatify\Paymob\Order\ValueObject\OrderItem('item', 100, 1)],
        );
        $orderIntent = $orderCommand->toArray();
        self::assertSame([
            'amount_cents' => 100,
            'currency' => 'EGP',
            'merchant_order_id' => 'order-ref',
            'items' => [['name' => 'item', 'amount_cents' => 100, 'quantity' => 1]],
        ], $orderIntent);
        self::assertArrayNotHasKey('auth_token', $orderIntent);
        $orderApi = new QueueApiClient();
        $orderApi->postQueue = [['id' => 5, 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100]];
        (new OrderService($orderApi, new AuthService($orderApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->createOrder($orderCommand);
        self::assertSame(['auth_token' => 'cached', ...$orderIntent], $orderApi->postCalls[0]['body']);
        self::assertSame($orderIntent, $orderCommand->toArray());

        $billing = new BillingData('First', 'Last', 'first@example.test', '01000000000');
        $keyCommand = new GeneratePaymentKeyCommand(5, 1, 100, CurrencyEnum::EGP, $billing);
        $keyIntent = $keyCommand->toArray();
        self::assertSame(['order_id', 'integration_id', 'amount_cents', 'currency', 'expiration', 'billing_data'], array_keys($keyIntent));
        self::assertSame(180, $keyIntent['expiration']);
        self::assertArrayNotHasKey('auth_token', $keyIntent);
        $keyApi = new QueueApiClient();
        $keyApi->postQueue = [['token' => 'payment-key']];
        (new PaymentKeyService($keyApi, new AuthService($keyApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->generate($keyCommand);
        self::assertSame(['auth_token' => 'cached', ...$keyIntent], $keyApi->postCalls[0]['body']);
        self::assertSame($keyIntent, $keyCommand->toArray());

        $kioskCommand = new InitiateKioskPaymentCommand('payment-key');
        $kioskIntent = $kioskCommand->toArray();
        self::assertSame([
            'source' => ['identifier' => 'AGGREGATOR', 'subtype' => 'AGGREGATOR'],
            'payment_token' => 'payment-key',
        ], $kioskIntent);
        self::assertArrayNotHasKey('auth_token', $kioskIntent);
        $kioskApi = new QueueApiClient();
        $kioskApi->postQueue = [[
            'id' => 10, 'amount_cents' => 100, 'currency' => 'EGP', 'pending' => false, 'success' => true,
            'order' => ['id' => 5, 'merchant_order_id' => 'order-ref'],
        ]];
        (new KioskPaymentService($kioskApi, new AuthService($kioskApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->pay($kioskCommand);
        self::assertSame(['auth_token' => 'cached', ...$kioskIntent], $kioskApi->postCalls[0]['body']);
        self::assertSame($kioskIntent, $kioskCommand->toArray());
    }

    public function testOrderServiceMalformedRequiredResponseThrowsPackageApiExceptionWithEvidence(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $response = ['id' => 'invalid-id', 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        $service = new OrderService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));

        try {
            $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP, 'order-ref', []));
            self::fail('Malformed required Order response must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
            self::assertNull($exception->getProviderStatusCode());
        }
    }

    public function testOrderServiceUnsupportedCurrencyWrapsOriginalInvalidArgumentException(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $response = ['id' => 42, 'created_at' => 'now', 'currency' => 'UNSUPPORTED', 'amount_cents' => 100];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        $service = new OrderService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));

        try {
            $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP, 'order-ref', []));
            self::fail('Unsupported Order currency must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
            self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testPaymentKeyServiceMissingTokenThrowsPackageApiExceptionWithEvidence(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $response = ['order' => 42, 'status' => 'issued'];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        $service = new PaymentKeyService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));
        $command = new GeneratePaymentKeyCommand(42, 1, 100, CurrencyEnum::EGP, new BillingData('First', 'Last', 'first@example.test', '01000000000'));

        try {
            $service->generate($command);
            self::fail('Payment Key response without a token must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
            self::assertNull($exception->getProviderStatusCode());
        }
    }

    public function testPaymentKeyServiceRejectsWhitespaceTokenAndPreservesOriginalEvidence(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $response = ['token' => " \t ", 'order' => 42, 'provider_flag' => true];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        $service = new PaymentKeyService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));
        $command = new GeneratePaymentKeyCommand(42, 1, 100, CurrencyEnum::EGP, new BillingData('First', 'Last', 'first@example.test', '01000000000'));

        try {
            $service->generate($command);
            self::fail('Whitespace-only Payment Key token must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
            self::assertNull($exception->getProviderStatusCode());
            self::assertCount(1, $api->postCalls);
        }

        $validTokenApi = new QueueApiClient();
        $validTokenApi->postQueue = [['token' => " \tvalid-key "]];
        $validToken = (new PaymentKeyService(
            $validTokenApi,
            new AuthService($validTokenApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)),
        ))->generate($command);
        self::assertSame(" \tvalid-key ", $validToken->token);
    }

    public function testGuzzleAdapterPreservesBasePathForAuthGetPostAndDoesNotFollowRedirects(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3, 'https://gateway.example/paymob/api/');
        $adapter = new GuzzleApiClient($config);
        $clientProperty = new \ReflectionProperty($adapter, 'client');
        $guzzleClient = $clientProperty->getValue($adapter);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"token":"auth-token","profile":{"id":7}}'),
            new Response(200, [], '{"read":true}'),
            new Response(200, [], '{"created":true}'),
            new Response(302, ['Location' => 'https://redirect.example/elsewhere'], '{}'),
            new Response(200, [], '{"followed":true}'),
        ]));
        $stack->push(Middleware::history($history));
        $clientConfigProperty = new \ReflectionProperty(\GuzzleHttp\Client::class, 'config');
        $clientConfig = $clientConfigProperty->getValue($guzzleClient);
        $clientConfig['handler'] = $stack;
        $clientConfigProperty->setValue($guzzleClient, $clientConfig);

        $auth = new AuthService($adapter, $config, new InMemoryTokenRepository(), new FixedTestClock(1000));
        self::assertSame('auth-token', $auth->getToken()->token);
        self::assertSame(['read' => true], $adapter->get('/acceptance/transactions/123', ['source' => 'system'], ['X-Caller' => 'runtime-test']));
        self::assertSame(['created' => true], $adapter->post('/ecommerce/orders', ['id' => 42], ['X-Caller' => 'runtime-test']));
        self::assertSame([], $adapter->get('/redirect-check'));

        self::assertCount(4, $history, 'A 302 response must not trigger a second request.');
        self::assertSame('https://gateway.example/paymob/api/auth/tokens', (string) $history[0]['request']->getUri());
        self::assertSame('https://gateway.example/paymob/api/acceptance/transactions/123?source=system', (string) $history[1]['request']->getUri());
        self::assertSame('runtime-test', $history[1]['request']->getHeaderLine('X-Caller'));
        self::assertSame('https://gateway.example/paymob/api/ecommerce/orders', (string) $history[2]['request']->getUri());
        self::assertSame('runtime-test', $history[2]['request']->getHeaderLine('X-Caller'));
        self::assertSame(['id' => 42], json_decode((string) $history[2]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('https://gateway.example/paymob/api/redirect-check', (string) $history[3]['request']->getUri());
        self::assertFalse($guzzleClient->getConfig('allow_redirects'));
        self::assertFalse($guzzleClient->getConfig('http_errors'));
        self::assertTrue($guzzleClient->getConfig('verify'));
        self::assertSame(30, $guzzleClient->getConfig('timeout'));

        $defaultAdapter = new GuzzleApiClient(new PaymobConfig('key', 'hmac', 1, 2, 3));
        $defaultGuzzleClient = (new \ReflectionProperty($defaultAdapter, 'client'))->getValue($defaultAdapter);
        self::assertSame('https://accept.paymob.com/api/', (string) $defaultGuzzleClient->getConfig('base_uri'));
        $defaultHistory = [];
        $defaultStack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"token":"default-auth-token","profile":{"id":8}}'),
        ]));
        $defaultStack->push(Middleware::history($defaultHistory));
        $defaultClientConfig = $clientConfigProperty->getValue($defaultGuzzleClient);
        $defaultClientConfig['handler'] = $defaultStack;
        $clientConfigProperty->setValue($defaultGuzzleClient, $defaultClientConfig);
        $defaultAuth = new AuthService(
            $defaultAdapter,
            new PaymobConfig('key', 'hmac', 1, 2, 3),
            new InMemoryTokenRepository(),
            new FixedTestClock(1000),
        );
        self::assertSame('default-auth-token', $defaultAuth->getToken()->token);
        self::assertSame('https://accept.paymob.com/api/auth/tokens', (string) $defaultHistory[0]['request']->getUri());
    }

    public function testOrderServiceRejectsMalformedOptionalProviderFieldsWithFullEvidence(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        foreach ([
            ['id' => 42, 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100, 'merchant_order_id' => 123],
            ['id' => 42, 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100, 'items' => [['name' => 'ok'], 'bad-row']],
        ] as $response) {
            $api = new QueueApiClient();
            $api->postQueue = [$response];
            $service = new OrderService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));
            try {
                $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP, 'order-ref', []));
                self::fail('Malformed optional Order response must throw ApiException.');
            } catch (ApiException $exception) {
                self::assertSame($response, $exception->getResponse());
            }
        }
    }

    public function testKioskMalformedOptionalProviderFieldThrowsApiExceptionWithEvidence(): void
    {
        $response = ['id' => 10, 'amount_cents' => 100, 'currency' => 'EGP', 'pending' => false, 'success' => true,
            'order' => ['id' => 5, 'merchant_order_id' => 'ref'], 'data' => ['message' => []]];
        try {
            KioskPaymentResponseDTO::fromArray($response);
            self::fail('Malformed Kiosk optional field must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
        }
    }

    public function testWalletMalformedOptionalProviderFieldThrowsApiExceptionWithEvidence(): void
    {
        $response = ['id' => 10, 'amount_cents' => 100, 'currency' => 'EGP', 'success' => true, 'pending' => false,
            'created_at' => 'now', 'order' => ['id' => 5], 'redirect_url' => ['bad']];
        try {
            WalletPaymentResponseDTO::fromArray($response);
            self::fail('Malformed Wallet optional field must throw ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
        }
    }

    public function testTransactionMalformedOptionalProviderFieldsThrowApiExceptionWithEvidence(): void
    {
        $base = ['id' => 10, 'order_id' => 5, 'amount_cents' => 100, 'currency' => 'EGP', 'success' => true,
            'pending' => false, 'is_captured' => false, 'is_refunded' => false, 'is_voided' => false,
            'is_3d_secure' => false, 'is_standalone_payment' => true];
        foreach ([
            [...$base, 'integration_id' => '12'],
            [...$base, 'order' => ['id' => 5, 'items' => 'bad-shape']],
        ] as $response) {
            try {
                TransactionResponseDTO::fromArray($response);
                self::fail('Malformed Transaction optional field must throw ApiException.');
            } catch (ApiException $exception) {
                self::assertSame($response, $exception->getResponse());
            }
        }
    }

    public function testProviderDiagnosticSnapshotsRemainAccessibleButAreExcludedFromJson(): void
    {
        $sentinel = ['__raw_provider_sentinel__' => 'must-not-be-json-serialized'];

        $order = new OrderResponseDTO(1, '2026-10-05T12:00:00Z', CurrencyEnum::EGP, 500, 'order-1', row: $sentinel);
        self::assertSame($sentinel, $order->row);
        self::assertArrayNotHasKey('row', $order->jsonSerialize());
        self::assertSame(1, $order->jsonSerialize()['id']);
        $orderJson = json_encode($order, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__raw_provider_sentinel__', $orderJson);
        self::assertSame($sentinel, $order->row);

        $kiosk = new KioskPaymentResponseDTO(10, 1, 'order-1', 500, CurrencyEnum::EGP, false, true, row: $sentinel);
        self::assertSame($sentinel, $kiosk->row);
        self::assertArrayNotHasKey('row', $kiosk->jsonSerialize());
        self::assertSame(10, $kiosk->jsonSerialize()['transactionId']);
        $kioskJson = json_encode($kiosk, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__raw_provider_sentinel__', $kioskJson);
        self::assertSame($sentinel, $kiosk->row);

        $transaction = new TransactionResponseDTO(10, 1, 500, CurrencyEnum::EGP, true, false, true, false, false, true, false, raw: $sentinel);
        self::assertSame($sentinel, $transaction->raw);
        self::assertArrayNotHasKey('raw', $transaction->jsonSerialize());
        self::assertSame(10, $transaction->jsonSerialize()['id']);
        $transactionJson = json_encode($transaction, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__raw_provider_sentinel__', $transactionJson);
        self::assertSame($sentinel, $transaction->raw);

        $webhook = new WebhookPayloadDTO(10, 1, 500, 'EGP', true, false, hmac: 'typed-hmac-sentinel', raw: $sentinel);
        self::assertSame($sentinel, $webhook->raw);
        self::assertArrayNotHasKey('raw', $webhook->jsonSerialize());
        self::assertSame('typed-hmac-sentinel', $webhook->jsonSerialize()['hmac']);
        $webhookJson = json_encode($webhook, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__raw_provider_sentinel__', $webhookJson);
        self::assertSame($sentinel, $webhook->raw);
    }

    public function testKioskFlowJsonDoesNotExposeNestedOrderOrKioskDiagnosticSnapshots(): void
    {
        $orderSentinel = ['__order_row_sentinel__' => 'private diagnostic'];
        $kioskSentinel = ['__kiosk_row_sentinel__' => 'private diagnostic'];
        $order = new OrderResponseDTO(1, '2026-10-05T12:00:00Z', CurrencyEnum::EGP, 500, 'order-1', row: $orderSentinel);
        $kiosk = new KioskPaymentResponseDTO(10, 1, 'order-1', 500, CurrencyEnum::EGP, false, true, row: $kioskSentinel);
        $flow = new KioskFlowResultDTO($order, $kiosk);

        self::assertSame($orderSentinel, $flow->order->row);
        self::assertSame($kioskSentinel, $flow->kiosk->row);
        $json = json_encode($flow, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__order_row_sentinel__', $json);
        self::assertStringNotContainsString('__kiosk_row_sentinel__', $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('row', $decoded['order']);
        self::assertArrayNotHasKey('row', $decoded['kiosk']);
        self::assertSame($orderSentinel, $flow->order->row);
        self::assertSame($kioskSentinel, $flow->kiosk->row);
    }

    public function testWalletFlowJsonDoesNotExposeNestedOrderDiagnosticSnapshot(): void
    {
        $orderSentinel = ['__wallet_order_row_sentinel__' => 'private diagnostic'];
        $order = new OrderResponseDTO(1, '2026-10-05T12:00:00Z', CurrencyEnum::EGP, 500, 'order-1', row: $orderSentinel);
        $wallet = new WalletPaymentResponseDTO(11, 1, 500, CurrencyEnum::EGP, true, false, '2026-10-05T12:00:00Z');
        $flow = new WalletFlowResultDTO($order, $wallet);

        self::assertSame($orderSentinel, $flow->order->row);
        $json = json_encode($flow, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('__wallet_order_row_sentinel__', $json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('row', $decoded['order']);
        self::assertSame($orderSentinel, $flow->order->row);
    }

    public function testInMemoryTokensAreIsolatedByScope(): void
    {
        $repo = new InMemoryTokenRepository();
        $a = new TokenScopeTestFactory('a');
        $b = new TokenScopeTestFactory('b');
        $repo->save($a->scope, new TokenResponseDTO('token-a', 1, 10, 20));
        $repo->save($b->scope, new TokenResponseDTO('token-b', 2, 10, 20));
        $repo->clear($a->scope);
        self::assertNull($repo->get($a->scope));
        self::assertSame('token-b', $repo->get($b->scope)?->token);
    }

    public function testFileRepositoryRoundTripCorruptionIsolationAndClear(): void
    {
        $directory = sys_get_temp_dir() . '/paymob-token-test-' . bin2hex(random_bytes(6));
        $repo = new FileTokenRepository($directory);
        $a = new TokenScopeTestFactory('a');
        $b = new TokenScopeTestFactory('b');
        self::assertNull($repo->get($a->scope));
        $repo->save($a->scope, new TokenResponseDTO('token-a', 1, 10, 3610));
        $repo->save($b->scope, new TokenResponseDTO('token-b', 2, 10, 3610));
        self::assertSame('token-a', $repo->get($a->scope)?->token);
        self::assertSame('token-b', $repo->get($b->scope)?->token);
        $aFile = $directory . '/' . $a->scope->value() . '.json';
        self::assertStringNotContainsString('secret-key', basename($aFile));
        file_put_contents($aFile, '{broken');
        try { $repo->get($a->scope); self::fail('Corrupt JSON must fail closed.'); }
        catch (\Maatify\Paymob\Exception\TokenStorageException) {}
        file_put_contents($aFile, '{"token":"only"}');
        try { $repo->get($a->scope); self::fail('Missing required fields must fail closed.'); }
        catch (\Maatify\Paymob\Exception\TokenStorageException) {}
        $repo->save($a->scope, new TokenResponseDTO('token-a', 1, 10, 3610));
        $repo->clear($a->scope);
        self::assertNull($repo->get($a->scope));
        self::assertSame('token-b', $repo->get($b->scope)?->token);
        foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }

    public function testFileRepositoryRejectsSemanticallyCorruptReadAndSaveWithoutReplacingValidState(): void
    {
        $directory = sys_get_temp_dir() . '/paymob-token-corrupt-' . bin2hex(random_bytes(6));
        $repo = new FileTokenRepository($directory);
        $scope = (new TokenScopeTestFactory('corrupt-file'))->scope;
        $path = $directory . '/' . $scope->value() . '.json';
        $valid = ['token' => 'valid-token', 'profile_id' => 1, 'issued_at' => 100, 'expires_at' => 3700];
        $repo->save($scope, new TokenResponseDTO('valid-token', 1, 100, 3700));

        foreach ([
            [...$valid, 'token' => " \t\n"],
            [...$valid, 'profile_id' => 0],
            [...$valid, 'issued_at' => -1, 'expires_at' => 3599],
            [...$valid, 'expires_at' => 100],
            [...$valid, 'expires_at' => 99],
            [...$valid, 'expires_at' => 3701],
        ] as $corrupt) {
            file_put_contents($path, json_encode($corrupt, JSON_THROW_ON_ERROR));
            try { $repo->get($scope); self::fail('Semantically corrupt file token must fail closed.'); }
            catch (TokenStorageException) { self::assertTrue(true); }
        }

        $repo->save($scope, new TokenResponseDTO('valid-token', 1, 100, 3700));
        foreach ([
            new TokenResponseDTO('', 1, 100, 3700),
            new TokenResponseDTO(" \t", 1, 100, 3700),
            new TokenResponseDTO('bad-profile', 0, 100, 3700),
            new TokenResponseDTO('bad-issued', 1, -1, 3599),
            new TokenResponseDTO('bad-expiry', 1, 100, 100),
            new TokenResponseDTO('bad-lifetime', 1, 100, 3701),
        ] as $corrupt) {
            try { $repo->save($scope, $corrupt); self::fail('Semantically corrupt save must fail.'); }
            catch (TokenStorageException) { self::assertSame('valid-token', $repo->get($scope)?->token); }
        }

        foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }

    public function testAuthRejectsCorruptTypedCachedStateWithoutRequestAndRefreshesValidExpiredCache(): void
    {
        $config = new PaymobConfig('cached-api-key', 'hmac', 1, 2, 3);
        $scope = TokenScope::fromConfig($config);
        $corruptRepo = new InMemoryTokenRepository();
        $corruptRepo->save($scope, new TokenResponseDTO('still-live', 1, 0, 3601));
        $api = new QueueApiClient();
        try { (new AuthService($api, $config, $corruptRepo, new FixedTestClock(1000)))->getToken(); self::fail('Invalid lifetime must be rejected before Auth.'); }
        catch (TokenStorageException) { self::assertCount(0, $api->postCalls); }

        $expiredRepo = new InMemoryTokenRepository();
        $expiredRepo->save($scope, new TokenResponseDTO('expired', 1, 0, 3600));
        $api->postQueue = [['token' => 'fresh-after-expiry', 'profile' => ['id' => 1]]];
        $fresh = (new AuthService($api, $config, $expiredRepo, new FixedTestClock(3600)))->getToken();
        self::assertSame('fresh-after-expiry', $fresh->token);
        self::assertCount(1, $api->postCalls);
    }

    public function testAuthRejectsWhitespaceProviderTokenWithOriginalResponseEvidence(): void
    {
        $config = new PaymobConfig('whitespace-api-key', 'hmac', 1, 2, 3);
        $repo = new InMemoryTokenRepository();
        $response = ['token' => " \t\n", 'profile' => ['id' => 9]];
        $api = new QueueApiClient();
        $api->postQueue = [$response];
        try { (new AuthService($api, $config, $repo, new FixedTestClock(1000)))->getToken(); self::fail('Whitespace provider token must be malformed.'); }
        catch (ApiException $exception) {
            self::assertSame($response, $exception->getResponse());
            self::assertNull($repo->get(TokenScope::fromConfig($config)));
        }
    }

    public function testAuthUsesCacheClockAndExactProviderTtl(): void
    {
        $config = new PaymobConfig('api-key', 'hmac', 1, 2, 3);
        $clock = new FixedTestClock(1000);
        $repo = new InMemoryTokenRepository();
        $api = new QueueApiClient();
        $api->postQueue = [['token' => 'fresh-token', 'profile' => ['id' => 42]]];
        $service = new AuthService($api, $config, $repo, $clock);
        $token = $service->getToken();
        self::assertSame(4600, $token->expiresAt);
        self::assertSame($token, $service->getToken());
        self::assertCount(1, $api->postCalls);
    }

    public function testExpiredAndForcedAuthRefreshReplaceOnlyTheCurrentScope(): void
    {
        $config = new PaymobConfig('api-key', 'hmac', 1, 2, 3);
        $repo = new InMemoryTokenRepository();
        $scope = TokenScope::fromConfig($config);
        $otherScope = TokenScope::fromConfig(new PaymobConfig('other-key', 'hmac', 1, 2, 3));
        $repo->save($scope, new TokenResponseDTO('expired', 1, 0, 3600));
        $repo->save($otherScope, new TokenResponseDTO('other-live', 2, 500, 4100));
        $api = new QueueApiClient();
        $api->postQueue = [['token' => 'fresh-one', 'profile' => ['id' => 1]], ['token' => 'fresh-two', 'profile' => ['id' => 1]]];
        $service = new AuthService($api, $config, $repo, new FixedTestClock(4000));
        self::assertSame('fresh-one', $service->getToken()->token);
        self::assertSame('fresh-two', $service->getToken(forceRefresh: true)->token);
        self::assertSame('other-live', $repo->get($otherScope)?->token);
        self::assertCount(2, $api->postCalls);
    }

    public function testWallet401IsPropagatedWithoutAuthOrReplay(): void
    {
        $api = new QueueApiClient();
        $api->postQueue = [new UnauthorizedException('Unauthorized', 401, ['message' => 'Unauthorized'])];
        $service = new WalletPaymentService($api);
        try { $service->pay(new InitiateWalletPaymentCommand('payment-key', '01000000000')); self::fail('Expected 401.'); }
        catch (UnauthorizedException) {}
        self::assertCount(1, $api->postCalls);
        self::assertSame('/acceptance/payments/pay', $api->postCalls[0]['uri']);
        self::assertArrayNotHasKey('auth_token', $api->postCalls[0]['body']);
    }

    public function testProviderClassificationIncludesAllHttpErrorsAndAllows201(): void
    {
        self::assertSame(['id' => 1], ResponseDecoder::decode(200, '{"id":1}'));
        self::assertSame(['token' => 'x'], ResponseDecoder::decode(201, '{"token":"x"}'));
        foreach ([400, 401, 404, 429, 500] as $status) {
            try { ResponseDecoder::decode($status, '{"message":"no"}'); self::fail("Expected status {$status} failure."); }
            catch (PaymobExceptionInterface $e) {
                self::assertSame($status, $e->getProviderStatusCode());
                self::assertNotSame($status, $e->getCode());
            }
        }
        try { ResponseDecoder::decode(400, '{"error_code":"validation_failed","message":"invalid"}'); self::fail('Validation provider errors retain their named class.'); }
        catch (\Maatify\Paymob\Exception\ValidationException $e) { self::assertSame(400, $e->getProviderStatusCode()); }
        try { ResponseDecoder::decode(200, '{bad'); self::fail('Malformed successful JSON must fail.'); }
        catch (ApiException $e) { self::assertNull($e->getProviderStatusCode()); }
        try { throw new NetworkException('network'); }
        catch (NetworkException $e) { self::assertSame('network', $e->getMessage()); }
    }

    public function testNonJsonProviderFailuresKeepTypedClassificationStatusAndRawBody(): void
    {
        $expectedClasses = [
            400 => ApiException::class,
            401 => UnauthorizedException::class,
            404 => NotFoundException::class,
            429 => RateLimitException::class,
            500 => ServiceUnavailableException::class,
        ];

        foreach ($expectedClasses as $status => $expectedClass) {
            $body = "plain provider failure {$status}";
            try {
                ResponseDecoder::decode($status, $body);
                self::fail("Expected non-JSON provider failure for HTTP {$status}.");
            } catch (PaymobExceptionInterface $exception) {
                self::assertSame($expectedClass, $exception::class);
                self::assertSame($status, $exception->getProviderStatusCode());
                self::assertSame($body, $exception->getResponse());
            }
        }
    }

    public function testNamedExceptionsExposeStableMaatifySemanticsAndProviderEvidence(): void
    {
        $previous = new RuntimeException('prior provider failure');
        $evidence = ['provider' => 'response'];
        $cases = [
            [new UnauthorizedException('unauthorized', 401, $evidence, $previous), 'AUTHENTICATION', 'UNAUTHORIZED', 401, true, false],
            [new ValidationException('invalid', 422, $evidence, $previous), 'VALIDATION', 'INVALID_ARGUMENT', 422, true, false],
            [new NotFoundException('missing', 404, $evidence, $previous), 'NOT_FOUND', 'RESOURCE_NOT_FOUND', 404, true, false],
            [new RateLimitException('slow down', 429, $evidence, $previous), 'RATE_LIMIT', 'TOO_MANY_REQUESTS', 429, true, true],
            [new DuplicateReferenceException('duplicate', 422, $evidence, $previous), 'CONFLICT', 'CONFLICT', 422, true, false],
            [new AuthException('auth operation failed', 400, $evidence, $previous), 'AUTHENTICATION', 'AUTH_STATE_VIOLATION', 400, true, false],
        ];
        foreach ($cases as [$exception, $category, $errorCode, $httpStatus, $safe, $retryable]) {
            self::assertInstanceOf(PaymobExceptionInterface::class, $exception);
            self::assertSame($category, $exception->getCategory()->getValue());
            self::assertSame($errorCode, $exception->getErrorCode()->getValue());
            self::assertSame($httpStatus, $exception->getHttpStatus());
            self::assertSame($safe, $exception->isSafe());
            self::assertSame($retryable, $exception->isRetryable());
            self::assertSame($httpStatus, $exception->getProviderStatusCode());
            self::assertSame($httpStatus, $exception->getStatusCode());
            self::assertSame($evidence, $exception->getResponse());
            self::assertSame($previous, $exception->getPrevious());
        }

        $optional = new OptionalCapabilityUnavailableException('optional capability unavailable');
        self::assertSame('UNSUPPORTED', $optional->getCategory()->getValue());
        self::assertSame('UNSUPPORTED_OPERATION', $optional->getErrorCode()->getValue());
        self::assertSame(409, $optional->getHttpStatus());
        self::assertTrue($optional->isSafe());
        self::assertFalse($optional->isRetryable());

        foreach ([new WebhookException('invalid webhook'), new ReturnUrlException('invalid return URL')] as $securityException) {
            self::assertInstanceOf(PaymobExceptionInterface::class, $securityException);
            self::assertSame('SECURITY', $securityException->getCategory()->getValue());
            self::assertSame('MAATIFY_ERROR', $securityException->getErrorCode()->getValue());
            self::assertSame(403, $securityException->getHttpStatus());
            self::assertTrue($securityException->isSafe());
        }

        foreach ([
            UnauthorizedException::class, ValidationException::class, NotFoundException::class,
            RateLimitException::class, DuplicateReferenceException::class, AuthException::class,
            ApiException::class, NetworkException::class, TokenStorageException::class,
            OptionalCapabilityUnavailableException::class, WebhookException::class, ReturnUrlException::class,
            ServiceUnavailableException::class, \Maatify\Paymob\Exception\OrderException::class,
            \Maatify\Paymob\Exception\TransactionException::class,
            \Maatify\Paymob\Exception\InvalidRequestException::class,
        ] as $exceptionClass) {
            self::assertTrue(is_subclass_of($exceptionClass, PaymobExceptionInterface::class), $exceptionClass);
        }
        self::assertSame('SYSTEM', (new \Maatify\Paymob\Exception\PaymobException('system'))->getCategory()->getValue());
        self::assertSame('MAATIFY_ERROR', (new \Maatify\Paymob\Exception\PaymobException('system'))->getErrorCode()->getValue());
    }

    public function testInvalidRequestExceptionUsesValidationSemanticsAndPreservesProviderContext(): void
    {
        $default = new InvalidRequestException('invalid request');
        self::assertInstanceOf(PaymobExceptionInterface::class, $default);
        self::assertSame('VALIDATION', $default->getCategory()->getValue());
        self::assertSame('INVALID_ARGUMENT', $default->getErrorCode()->getValue());
        self::assertSame(400, $default->getHttpStatus());
        self::assertTrue($default->isSafe());
        self::assertFalse($default->isRetryable());

        $response = ['error_code' => 'invalid_params', 'detail' => 'Invalid request'];
        $previous = new RuntimeException('provider context');
        $withProviderContext = new InvalidRequestException('invalid request', 422, $response, $previous);
        self::assertSame(422, $withProviderContext->getProviderStatusCode());
        self::assertSame(422, $withProviderContext->getStatusCode());
        self::assertSame(422, $withProviderContext->getHttpStatus());
        self::assertSame($response, $withProviderContext->getResponse());
        self::assertSame($previous, $withProviderContext->getPrevious());
    }

    public function testExceptionFactoryStatusPrecedenceAndRateLimitDoesNotRetry(): void
    {
        $factoryCases = [
            [401, 'validation_failed', UnauthorizedException::class],
            [404, 'duplicate_reference', NotFoundException::class],
            [429, 'validation_failed', RateLimitException::class],
            [500, 'validation_failed', ServiceUnavailableException::class],
            [400, 'duplicate_reference', DuplicateReferenceException::class],
        ];
        foreach ($factoryCases as [$status, $bodyCode, $expected]) {
            $exception = PaymobExceptionFactory::fromResponse(['error_code' => $bodyCode], $status);
            self::assertSame($expected, $exception::class);
            self::assertSame($status, $exception->getProviderStatusCode());
        }

        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $api = new QueueApiClient();
        $api->postQueue = [new RateLimitException('slow down', 429)];
        $service = new OrderService($api, new AuthService($api, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000)));
        try {
            $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP, 'order', []));
            self::fail('Expected rate-limit failure.');
        } catch (RateLimitException) {}
        self::assertCount(1, $api->postCalls, 'Retryable Maatify metadata must not enable generic Runtime retry.');
    }

    public function testMalformedProviderErrorMetadataFailsClosedWithoutDiagnostics(): void
    {
        $expected = [
            400 => ApiException::class,
            401 => UnauthorizedException::class,
            404 => NotFoundException::class,
            429 => RateLimitException::class,
            500 => ServiceUnavailableException::class,
        ];
        foreach ($expected as $status => $exceptionClass) {
            foreach ([[], (object)['nested' => 'value'], null, 7, false] as $invalidMessage) {
                $response = [
                    'status' => ['bad' => true],
                    'error_code' => (object)['bad' => true],
                    'code' => ['bad' => true],
                    'detail' => $invalidMessage,
                    'message' => $invalidMessage,
                    'unrelated' => ['nested' => (object)['value' => 'preserved']],
                ];
                $json = json_encode($response, JSON_THROW_ON_ERROR);
                set_error_handler(static function (int $severity, string $message): never {
                    throw new ErrorException($message, 0, $severity);
                });
                try {
                    try {
                        ResponseDecoder::decode($status, $json);
                        self::fail("Expected provider failure for HTTP {$status}.");
                    } catch (PaymobExceptionInterface $exception) {
                        self::assertSame($exceptionClass, $exception::class);
                        self::assertSame($status, $exception->getProviderStatusCode());
                        self::assertSame($status, $exception->getStatusCode());
                        self::assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $exception->getResponse());
                        self::assertSame('Unknown error from Paymob', $exception->getMessage());
                    }

                    $directResponse = [
                        'message' => (object)['bad' => true],
                        'detail' => (object)['bad' => true],
                        'status' => (object)['bad' => true],
                        'error_code' => (object)['bad' => true],
                        'code' => (object)['bad' => true],
                    ];
                    $direct = PaymobExceptionFactory::fromResponse($directResponse, $status);
                    self::assertSame($exceptionClass, $direct::class);
                    self::assertSame($directResponse, $direct->getResponse());
                    self::assertSame('Unknown error from Paymob', $direct->getMessage());
                } finally {
                    restore_error_handler();
                }
            }
        }
    }

    public function testProviderErrorFactoryRetainsValidBodyCodeAndMessageMappings(): void
    {
        $cases = [
            [['error_code' => 'unauthorized', 'detail' => 'denied'], UnauthorizedException::class, 'denied'],
            [['code' => 'validation_failed', 'message' => 'invalid'], ValidationException::class, 'invalid'],
            [['status' => 'not_found', 'detail' => 'missing'], NotFoundException::class, 'missing'],
            [['error_code' => 'duplicate_reference', 'detail' => 'duplicate'], DuplicateReferenceException::class, 'duplicate'],
            [['error_code' => [], 'code' => 'validation_failed', 'message' => 'valid fallback'], ValidationException::class, 'valid fallback'],
        ];
        foreach ($cases as [$response, $expectedClass, $expectedMessage]) {
            $exception = PaymobExceptionFactory::fromResponse($response, 400);
            self::assertSame($expectedClass, $exception::class);
            self::assertSame($expectedMessage, $exception->getMessage());
            self::assertSame($response, $exception->getResponse());
        }
    }

    public function testMySqlTokenHydrationValidatesStoredMixedValuesBeforeConversion(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $scope = TokenScope::fromConfig($config);
        $valid = ['token' => 'cached-token', 'profile_id' => '42', 'issued_at' => 0, 'expires_at' => '3600'];
        $token = (new MySqlTokenRepository(new HydrationTestPDO($valid)))->get($scope);
        self::assertNotNull($token);
        self::assertSame(['cached-token', 42, 0, 3600], [$token->token, $token->profileId, $token->issuedAt, $token->expiresAt]);

        $invalidRows = [
            ['token' => [], 'profile_id' => 42, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => '', 'profile_id' => 42, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => '   ', 'profile_id' => 42, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => true, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 1.0, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => null, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => '01', 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => '+1', 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => '1.0', 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => '1e0', 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => '999999999999999999999999999999', 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 0, 'issued_at' => 0, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => -1, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => false, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => '1e0', 'expires_at' => 2],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => '999999999999999999999999999999', 'expires_at' => 999999999999],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 1, 'expires_at' => 1],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 1, 'expires_at' => 0],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 0, 'expires_at' => 1.0],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 0, 'expires_at' => null],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 0, 'expires_at' => '1.0'],
            ['token' => 'token', 'profile_id' => 1, 'issued_at' => 0, 'expires_at' => '999999999999999999999999999999'],
        ];

        foreach ($invalidRows as $row) {
            try {
                (new MySqlTokenRepository(new HydrationTestPDO($row)))->get($scope);
                self::fail('Malformed persisted token row must fail explicitly.');
            } catch (TokenStorageException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOrderOne401RecoveryPerformsOnlyOneReplay(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $repo = new InMemoryTokenRepository();
        $scope = TokenScope::fromConfig($config);
        $repo->save($scope, new TokenResponseDTO('old', 1, 1, 3601));
        $api = new QueueApiClient();
        $api->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new', 'profile' => ['id' => 1]],
            ['id' => 5, 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100]];
        $auth = new AuthService($api, $config, $repo, new FixedTestClock(1000));
        $service = new OrderService($api, $auth);
        $command = new CreateOrderCommand(100, CurrencyEnum::EGP);
        $order = $service->createOrder($command);
        self::assertSame(5, $order->id);
        self::assertCount(3, $api->postCalls);
        self::assertSame('old', $api->postCalls[0]['body']['auth_token']);
        self::assertSame('new', $api->postCalls[2]['body']['auth_token']);
        self::assertSame(
            array_diff_key($api->postCalls[0]['body'], ['auth_token' => true]),
            array_diff_key($api->postCalls[2]['body'], ['auth_token' => true]),
        );
    }

    public function testPaymentKeyKioskAndTransactionUseOneSameScope401Replay(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $billing = new BillingData('First', 'Last', 'a@example.com', '01000000000');

        $keyRepo = $this->repoWithCachedToken($config);
        $keyApi = new QueueApiClient();
        $keyApi->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new-key-token', 'profile' => ['id' => 2]], ['token' => 'payment-key']];
        $keyService = new PaymentKeyService($keyApi, new AuthService($keyApi, $config, $keyRepo, new FixedTestClock(1000)));
        $key = $keyService->generate(new GeneratePaymentKeyCommand(10, 1, 100, CurrencyEnum::EGP, $billing));
        self::assertSame('payment-key', $key->token);
        self::assertCount(3, $keyApi->postCalls);
        self::assertSame('new-key-token', $keyApi->postCalls[2]['body']['auth_token']);
        self::assertSame(
            array_diff_key($keyApi->postCalls[0]['body'], ['auth_token' => true]),
            array_diff_key($keyApi->postCalls[2]['body'], ['auth_token' => true]),
        );

        $kioskRepo = $this->repoWithCachedToken($config);
        $kioskApi = new QueueApiClient();
        $kioskApi->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new-kiosk-token', 'profile' => ['id' => 2]],
            ['id' => 20, 'amount_cents' => 100, 'currency' => 'EGP', 'pending' => true, 'success' => false,
                'order' => ['id' => 10, 'merchant_order_id' => 'merchant-ref']]];
        $kioskService = new KioskPaymentService($kioskApi, new AuthService($kioskApi, $config, $kioskRepo, new FixedTestClock(1000)));
        self::assertSame(20, $kioskService->pay(new InitiateKioskPaymentCommand('payment-key'))->transactionId);
        self::assertCount(3, $kioskApi->postCalls);
        self::assertSame(
            array_diff_key($kioskApi->postCalls[0]['body'], ['auth_token' => true]),
            array_diff_key($kioskApi->postCalls[2]['body'], ['auth_token' => true]),
        );

        $transactionRepo = $this->repoWithCachedToken($config);
        $transactionApi = new QueueApiClient();
        $transactionApi->postQueue = [['token' => 'new-transaction-token', 'profile' => ['id' => 2]]];
        $transactionApi->getQueue = [new UnauthorizedException('Unauthorized', 401), [
            'id' => 30, 'order_id' => 10, 'amount_cents' => 100, 'currency' => 'EGP', 'success' => true, 'pending' => false,
            'is_captured' => true, 'is_refunded' => false, 'is_voided' => false, 'is_3d_secure' => true, 'is_standalone_payment' => false,
        ]];
        $transactionService = new \Maatify\Paymob\Transaction\Service\TransactionService(
            $transactionApi, new AuthService($transactionApi, $config, $transactionRepo, new FixedTestClock(1000)));
        self::assertSame(30, $transactionService->getTransaction(30)->id);
        self::assertCount(2, $transactionApi->getCalls);
        self::assertSame('Bearer new-transaction-token', $transactionApi->getCalls[1]['headers']['Authorization']);
    }

    public function testSecond401PropagatesWithoutThirdOperationAttempt(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $repo = $this->repoWithCachedToken($config);
        $api = new QueueApiClient();
        $api->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new', 'profile' => ['id' => 2]],
            new UnauthorizedException('Unauthorized again', 401)];
        $service = new OrderService($api, new AuthService($api, $config, $repo, new FixedTestClock(1000)));
        try { $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP)); self::fail('Second 401 must propagate.'); }
        catch (UnauthorizedException $e) { self::assertSame(401, $e->getProviderStatusCode()); }
        self::assertCount(3, $api->postCalls);
    }

    public function testSecond401ForPaymentKeyKioskAndTransactionDoesNotRepeatAgain(): void
    {
        $config = new PaymobConfig('key', 'hmac', 1, 2, 3);
        $billing = new BillingData('First', 'Last', 'a@example.com', '01000000000');

        $keyApi = new QueueApiClient();
        $keyApi->postQueue = [new UnauthorizedException('first', 401), ['token' => 'new', 'profile' => ['id' => 1]], new UnauthorizedException('second', 401)];
        try { (new PaymentKeyService($keyApi, new AuthService($keyApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->generate(new GeneratePaymentKeyCommand(10, 1, 100, CurrencyEnum::EGP, $billing)); self::fail('Second Payment Key 401 must propagate.'); }
        catch (UnauthorizedException) {}
        self::assertCount(3, $keyApi->postCalls);

        $kioskApi = new QueueApiClient();
        $kioskApi->postQueue = [new UnauthorizedException('first', 401), ['token' => 'new', 'profile' => ['id' => 1]], new UnauthorizedException('second', 401)];
        try { (new KioskPaymentService($kioskApi, new AuthService($kioskApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->pay(new InitiateKioskPaymentCommand('payment-key')); self::fail('Second Kiosk 401 must propagate.'); }
        catch (UnauthorizedException) {}
        self::assertCount(3, $kioskApi->postCalls);

        $transactionApi = new QueueApiClient();
        $transactionApi->postQueue = [['token' => 'new', 'profile' => ['id' => 1]]];
        $transactionApi->getQueue = [new UnauthorizedException('first', 401), new UnauthorizedException('second', 401)];
        try { (new \Maatify\Paymob\Transaction\Service\TransactionService($transactionApi,
            new AuthService($transactionApi, $config, $this->repoWithCachedToken($config), new FixedTestClock(1000))))
            ->getTransaction(10); self::fail('Second Transaction 401 must propagate.'); }
        catch (UnauthorizedException) {}
        self::assertCount(2, $transactionApi->getCalls);
        self::assertCount(1, $transactionApi->postCalls);
    }

    private function repoWithCachedToken(PaymobConfig $config): InMemoryTokenRepository
    {
        $repo = new InMemoryTokenRepository();
        $repo->save(TokenScope::fromConfig($config), new TokenResponseDTO('cached', 1, 500, 4100));
        return $repo;
    }
}

final readonly class FixedTestClock implements ClockInterface
{
    public function __construct(private int $timestamp) {}
    public function now(): DateTimeImmutable { return (new DateTimeImmutable())->setTimestamp($this->timestamp); }
    public function getTimezone(): DateTimeZone { return new DateTimeZone('UTC'); }
}

final readonly class TokenScopeTestFactory
{
    public \Maatify\Paymob\Authentication\ValueObject\TokenScope $scope;
    public function __construct(string $suffix)
    {
        $this->scope = TokenScope::fromConfig(new PaymobConfig('secret-key-' . $suffix, 'hmac', 1, 2, 3));
    }
}

final class QueueApiClient implements ApiClientInterface
{
    public array $postQueue = [];
    public array $getQueue = [];
    public array $postCalls = [];
    public array $getCalls = [];

    public function post(string $uri, array $body, array $headers = []): array
    {
        $this->postCalls[] = compact('uri', 'body', 'headers');
        return $this->next($this->postQueue);
    }
    public function get(string $uri, array $query = [], array $headers = []): array
    {
        $this->getCalls[] = compact('uri', 'query', 'headers');
        return $this->next($this->getQueue);
    }
    private function next(array &$queue): array
    {
        $value = array_shift($queue);
        if ($value instanceof \Throwable) throw $value;
        if (!is_array($value)) throw new RuntimeException('No queued fake response.');
        return $value;
    }
}

final class HydrationTestPDO extends \PDO
{
    public function __construct(private readonly mixed $row) {}

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === self::ATTR_DRIVER_NAME ? 'mysql' : null;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return new HydrationTestPDOStatement($this->row);
    }
}

final class HydrationTestPDOStatement extends \PDOStatement
{
    public function __construct(private readonly mixed $row) {}

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row;
    }
}
