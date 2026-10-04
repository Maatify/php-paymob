<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO;

final readonly class WalletPaymentService
{
    public function __construct(private ApiClientInterface $http) {}

    public function pay(InitiateWalletPaymentCommand $command): WalletPaymentResponseDTO
    {
        return WalletPaymentResponseDTO::fromArray($this->http->post('/acceptance/payments/pay', $command->toArray()));
    }
}
