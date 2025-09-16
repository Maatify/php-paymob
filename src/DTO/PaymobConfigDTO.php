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

final class PaymobConfigDTO
{
    public function __construct(
        public readonly string $apiKey,          // الـ API Key الأساسي
        public readonly int $integrationIdCard,  // Integration ID للكروت
        public readonly int $integrationIdKiosk, // Integration ID للكيوسك
        public readonly int $integrationIdWallet,// Integration ID للمحافظ
        public readonly string $baseUrl = 'https://accept.paymobsolutions.com/api' // URL الأساسي
    ) {}
}