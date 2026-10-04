<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-20
 * Time: 15:14
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Callback\DTO;

final readonly class ReturnUrlResponseDTO
{
    public function __construct(
        public int     $transactionId,
        public int     $orderId,
        public int     $amountCents,
        public string  $currency,
        public bool    $success,
        public bool    $pending,
        public ?string $message = null,
        public ?string $hmac    = null,
    ) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}