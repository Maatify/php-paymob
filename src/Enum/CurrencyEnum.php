<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:38
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Enum;

enum CurrencyEnum: string
{
    case EGP = 'EGP';
    case USD = 'USD';
    case EUR = 'EUR';

    public static function fromString(string $currency): self
    {
        return match (strtoupper($currency)) {
            'EGP' => self::EGP,
            'USD' => self::USD,
            'EUR' => self::EUR,
            default => throw new \InvalidArgumentException("Unsupported currency: $currency"),
        };
    }
}