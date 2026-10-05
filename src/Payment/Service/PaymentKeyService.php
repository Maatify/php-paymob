<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO;

final readonly class PaymentKeyService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function generate(GeneratePaymentKeyCommand $command): PaymentKeyResponseDTO
    {
        $uri = '/acceptance/payment_keys';
        $token = $this->authService->getToken()->token;
        $payload = ['auth_token' => $token, ...$command->toArray()];
        try { $response = $this->http->post($uri, $payload); }
        catch (UnauthorizedException $e) {
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $payload = ['auth_token' => $fresh, ...$command->toArray()];
            $response = $this->http->post($uri, $payload);
        }
        if (!isset($response['token']) || !is_string($response['token']) || trim($response['token']) === '') throw new ApiException('Paymob Payment Key response is missing its required token.', null, $response);
        return new PaymentKeyResponseDTO($response['token'], $command->orderId);
    }
}
