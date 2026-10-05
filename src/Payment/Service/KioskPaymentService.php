<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO;

final readonly class KioskPaymentService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function pay(InitiateKioskPaymentCommand $command): KioskPaymentResponseDTO
    {
        $uri = '/acceptance/payments/pay';
        $token = $this->authService->getToken()->token;
        $payload = ['auth_token' => $token, ...$command->toArray()];
        try { $response = $this->http->post($uri, $payload); }
        catch (UnauthorizedException $e) {
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $payload = ['auth_token' => $fresh, ...$command->toArray()];
            $response = $this->http->post($uri, $payload);
        }
        return KioskPaymentResponseDTO::fromArray($response);
    }
}
