<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 19:15
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO;
use Maatify\Paymob\Factory\PaymobExceptionFactory;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Throwable;

final readonly class PaymentKeyService
{
    public function __construct(
        private ApiClientInterface $http,
        private AuthService $authService
    ) {}

    /**
     * @throws Throwable
     */
    public function generate(GeneratePaymentKeyCommand $request): PaymentKeyResponseDTO
    {
        $uri = "/acceptance/payment_keys";

        try {
            $authToken = $this->authService->getToken()->token;

            $response = $this->http->post(
                $uri,
                $request->toArray($authToken)
            );
            return new PaymentKeyResponseDTO($response['token'], $request->orderId);
        } catch (Throwable $e) {
            // handle unauthorized → retry with fresh token
            if ($e->getCode() === 401) {
                $newToken = $this->authService->getToken(forceRefresh: true)->token;

                $response = $this->http->post(
                    $uri,
                    $request->toArray($newToken)
                );

                return PaymentKeyResponseDTO::fromArray($response);
            }

            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'transaction'
            );
        }
    }
}
