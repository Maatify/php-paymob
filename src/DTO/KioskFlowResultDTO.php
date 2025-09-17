<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 16:29
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO;

use Maatify\Paymob\DTO\Order\OrderResponseDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentResponseDTO;

final readonly class KioskFlowResultDTO
{
    public function __construct(
        public OrderResponseDTO $order,
        public KioskPaymentResponseDTO $kiosk
    ) {}
}
