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

use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\DTO\Auth\TokenResponseDTO;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\PaymobException;
use Maatify\Paymob\Http\ApiClient;
use Maatify\Paymob\Repository\InMemoryTokenRepository;
use Maatify\Paymob\Service\AuthService;

// ───── bootstrap ─────
require __DIR__ . '/bootstrap.php';

/** @var ExampleBootstrap $bootstrap */
$bootstrap = require __DIR__ . '/bootstrap.php';

// جهّز الـ services
$config = $bootstrap->config;
$client = $bootstrap->client;
$logger = $bootstrap->logger;

// Repository بسيط (ممكن تستبدله بـ MySQL أو Redis)
$repo = new InMemoryTokenRepository();

// AuthService
$authService = new AuthService($client, $config, $repo);

// ───── التنفيذ ─────
try {
    /** @var TokenResponseDTO $tokenDto */
    $tokenDto = $authService->getToken();

    echo "✅ Token generated successfully\n";
    echo "Token: {$tokenDto->token}\n";
    echo "Profile ID: {$tokenDto->profileId}\n";
    echo "Issued At: " . date('Y-m-d H:i:s', $tokenDto->issuedAt) . "\n";
    echo "Expires At: " . date('Y-m-d H:i:s', $tokenDto->expiresAt) . "\n";
} catch (AuthException $e) {
    echo "❌ Auth error: " . $e->getMessage() . PHP_EOL;
} catch (NetworkException $e) {
    echo "❌ Network error: " . $e->getMessage() . PHP_EOL;
} catch (ApiException $e) {
    echo "❌ API error: " . $e->getMessage() . PHP_EOL;
} catch (PaymobException $e) {
    echo "❌ General Paymob SDK error: " . $e->getMessage() . PHP_EOL;
}


