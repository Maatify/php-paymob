<?php

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO;

final readonly class PaymentKeyService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function generate(GeneratePaymentKeyCommand $command): PaymentKeyResponseDTO
    {
        $uri = '/acceptance/payment_keys';
        $token = $this->authService->getToken()->token;
        try { $response = $this->http->post($uri, $command->toArray($token)); }
        catch (ApiException $e) {
            if ($e->getProviderStatusCode() !== 401) throw $e;
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $response = $this->http->post($uri, $command->toArray($fresh));
        }
        if (!isset($response['token']) || !is_string($response['token']) || $response['token'] === '') throw new ApiException('Paymob Payment Key response is missing its required token.', null, $response);
        return new PaymentKeyResponseDTO($response['token'], $command->orderId);
    }
}
