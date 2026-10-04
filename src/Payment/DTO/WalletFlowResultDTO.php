<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 17:43
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\DTO;

use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO;

final readonly class WalletFlowResultDTO implements \JsonSerializable
{
    public function __construct(
        public OrderResponseDTO $order,
        public WalletPaymentResponseDTO $wallet
    ) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
