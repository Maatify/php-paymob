<?php

declare(strict_types=1);

namespace Maatify\Paymob\Tests\System\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Adapter\CurlApiClient;
use Maatify\Paymob\Adapter\ResponseDecoder;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\Repository\FileTokenRepository;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Repository\Pdo\MySqlTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\NotFoundException;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use Maatify\Paymob\Exception\RateLimitException;
use Maatify\Paymob\Exception\ServiceUnavailableException;
use Maatify\Paymob\Exception\TokenStorageException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Payment\Service\WalletPaymentService;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $repo->save($a->scope, new TokenResponseDTO('token-a', 1, 10, 20));
        $repo->save($b->scope, new TokenResponseDTO('token-b', 2, 10, 20));
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
        $repo->save($a->scope, new TokenResponseDTO('token-a', 1, 10, 20));
        $repo->clear($a->scope);
        self::assertNull($repo->get($a->scope));
        self::assertSame('token-b', $repo->get($b->scope)?->token);
        foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
        rmdir($directory);
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
        $repo->save($scope, new TokenResponseDTO('expired', 1, 1, 999));
        $repo->save($otherScope, new TokenResponseDTO('other-live', 2, 500, 2000));
        $api = new QueueApiClient();
        $api->postQueue = [['token' => 'fresh-one', 'profile' => ['id' => 1]], ['token' => 'fresh-two', 'profile' => ['id' => 1]]];
        $service = new AuthService($api, $config, $repo, new FixedTestClock(1000));
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
            catch (ApiException $e) {
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
            } catch (ApiException $exception) {
                self::assertSame($expectedClass, $exception::class);
                self::assertSame($status, $exception->getProviderStatusCode());
                self::assertSame($body, $exception->getResponse());
            }
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
        $repo->save($scope, new TokenResponseDTO('old', 1, 1, 2000));
        $api = new QueueApiClient();
        $api->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new', 'profile' => ['id' => 1]],
            ['id' => 5, 'created_at' => 'now', 'currency' => 'EGP', 'amount_cents' => 100]];
        $auth = new AuthService($api, $config, $repo, new FixedTestClock(1000));
        $service = new OrderService($api, $auth);
        $order = $service->createOrder(new CreateOrderCommand(100, CurrencyEnum::EGP));
        self::assertSame(5, $order->id);
        self::assertCount(3, $api->postCalls);
        self::assertSame('old', $api->postCalls[0]['body']['auth_token']);
        self::assertSame('new', $api->postCalls[2]['body']['auth_token']);
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

        $kioskRepo = $this->repoWithCachedToken($config);
        $kioskApi = new QueueApiClient();
        $kioskApi->postQueue = [new UnauthorizedException('Unauthorized', 401), ['token' => 'new-kiosk-token', 'profile' => ['id' => 2]],
            ['id' => 20, 'amount_cents' => 100, 'currency' => 'EGP', 'pending' => true, 'success' => false,
                'order' => ['id' => 10, 'merchant_order_id' => 'merchant-ref']]];
        $kioskService = new KioskPaymentService($kioskApi, new AuthService($kioskApi, $config, $kioskRepo, new FixedTestClock(1000)));
        self::assertSame(20, $kioskService->pay(new InitiateKioskPaymentCommand('payment-key'))->transactionId);
        self::assertCount(3, $kioskApi->postCalls);

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
        $repo->save(TokenScope::fromConfig($config), new TokenResponseDTO('cached', 1, 500, 2000));
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
