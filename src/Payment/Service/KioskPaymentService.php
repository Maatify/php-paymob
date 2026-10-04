<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 21:11
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO;
use Maatify\Paymob\Factory\PaymobExceptionFactory;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Throwable;

final readonly class KioskPaymentService
{
    public function __construct(
        private ApiClientInterface $http,
        private AuthService $authService
    ) {}

    /**
     * Pay via Kiosk and return typed response
     *
     * @throws Throwable
     */
    public function pay(InitiateKioskPaymentCommand $request): KioskPaymentResponseDTO
    {
        $uri = "/acceptance/payments/pay";

        try {
            $authToken = $this->authService->getToken()->token;

            $response = $this->http->post(
                $uri,
                $request->toArray($authToken)
            );

            return KioskPaymentResponseDTO::fromArray($response);
        } catch (Throwable $e) {
            // handle unauthorized → retry with fresh token
            if ($e->getCode() === 401) {
                $newToken = $this->authService->getToken(forceRefresh: true)->token;

                $response = $this->http->post(
                    $uri,
                    $request->toArray($newToken)
                );

                return KioskPaymentResponseDTO::fromArray($response);
            }

            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'kiosk'
            );
        }
    }
}
