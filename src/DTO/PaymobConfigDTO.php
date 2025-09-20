<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:00
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO;

final readonly class PaymobConfigDTO
{

    public function __construct(
        public string $apiKey,          // الـ API Key الأساسي
        public int $integrationIdCard,  // Integration ID للكروت
        public int $integrationIdKiosk, // Integration ID للكيوسك
        public int $integrationIdWallet,// Integration ID للمحافظ
        public string $baseUrl = 'https://accept.paymobsolutions.com/api', // URL الأساسي
        public string $hmacSecret,
) {}
}