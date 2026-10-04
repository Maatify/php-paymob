<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 17:18
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

/**
 * Example: Generate Paymob Auth Token
 * Created by Maatify.dev
 * Project: paymob-php
 */

use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\PaymobException;
use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Authentication\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\SharedCommon\Infrastructure\SystemClock;

// ───── bootstrap ─────

/** @var PaymobExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

// Prepare the services.
$config = $bootstrap->config;
$client = $bootstrap->client;
$logger = $bootstrap->logger;

// Use in-memory storage; consumers may inject another repository.
$repo = new InMemoryTokenRepository();

// AuthService
$authService = new AuthService($client, $config, $repo, new SystemClock(new \DateTimeZone('UTC')));

// Execute the example.
try {
    // The first call requests a token.
    /** @var TokenResponseDTO $tokenDto */
    $tokenDto = $authService->getToken();

    echo "✅ Token generated successfully\n";
    echo "Token: {$tokenDto->token}\n";
    echo "Profile ID: {$tokenDto->profileId}\n";
    echo "Issued At: " . date('Y-m-d H:i:s', $tokenDto->issuedAt) . "\n";
    echo "Expires At: " . date('Y-m-d H:i:s', $tokenDto->expiresAt) . "\n";


    // The second call reuses the in-memory token.
    $token2 = $authService->getToken();
    echo "2nd Token generated successfully" . PHP_EOL;
    echo "Token: {$token2->token}\n";
    echo "Profile ID: {$token2->profileId}\n";
    echo "Issued At: " . date('Y-m-d H:i:s', $token2->issuedAt) . "\n";
    echo "Expires At: " . date('Y-m-d H:i:s', $token2->expiresAt) . "\n";

} catch (AuthException $e) {
    echo "❌ Auth error: " . $e->getMessage() . PHP_EOL;
} catch (NetworkException $e) {
    echo "❌ Network error: " . $e->getMessage() . PHP_EOL;
} catch (ApiException $e) {
    echo "❌ API error: " . $e->getMessage() . PHP_EOL;
} catch (PaymobException $e) {
    echo "❌ General Paymob SDK error: " . $e->getMessage() . PHP_EOL;
}


