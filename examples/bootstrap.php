<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-05
 * Time: 16:54
 * Project: bitaqaty-reseller-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

// autoload
$autoloadFiles = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloadFiles as $file) {
    if (file_exists($file)) {
        require $file;
        break;
    }
}

use Maatify\Paymob\Adapter\ApiClient;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Adapter\CurlApiClient;
use Maatify\Paymob\Adapter\GuzzleApiClient;
use Maatify\Paymob\Config\PaymobConfig;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LogLevel;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

final readonly class PaymobExampleBootstrap
{
    public function __construct(
        public PaymobConfig $config,
        public ApiClientInterface $client,
        public Logger $logger,
        public ClockInterface $clock,
    )
    {
    }
}

return (function (): PaymobExampleBootstrap {
    // Logger setup
    $logger = new Logger('paymob.bootstrap');
    $logger->pushHandler(new StreamHandler(
        __DIR__ . '/../logs/paymob.bootstrap.log', // 👈 أضفنا .log
        LogLevel::DEBUG
    ));

    // Config setup
    // expireAt = minutes

    $config = new PaymobConfig(
        apiKey             : $_ENV['PAYMOB_API_KEY'],
        integrationIdCard  : (int)$_ENV['PAYMOB_INTEGRATION_ID_CARD'],
        integrationIdKiosk : (int)$_ENV['PAYMOB_INTEGRATION_ID_KIOSK'],
        integrationIdWallet: (int)$_ENV['PAYMOB_INTEGRATION_ID_WALLET'],
        baseUrl            : $_ENV['PAYMOB_BASE_URL'],
        hmacSecret         : $_ENV['PAYMOB_HMAC_SECRET'],
    );

    // Pick ONE client implementation 👇
    // $client = new GuzzleApiClient($config, $logger);
    // $client = new CurlApiClient($config, $logger);

    // Unified wrapper (recommended)
    $client = new ApiClient(
        config   : $config,
        logger   : $logger,
        channel  : 'examples',
        useGuzzle: false
    );

    return new PaymobExampleBootstrap($config, $client, $logger, new SystemClock(new \DateTimeZone('UTC')));
})();
