<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 17:44
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Payment\Service;

use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO;
use Maatify\Paymob\Factory\PaymobExceptionFactory;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Throwable;

final readonly class WalletPaymentService
{
    public function __construct(
        private ApiClientInterface $http,
        private AuthService $authService
    ) {}

    /**
     * Pay via Wallet and return typed response
     *
     * @throws Throwable
     */
    public function pay(InitiateWalletPaymentCommand $request): WalletPaymentResponseDTO
    {
        $uri = "/acceptance/payments/pay";

        try {
            $response = $this->http->post($uri, $request->toArray());

            return WalletPaymentResponseDTO::fromArray($response);
        } catch (Throwable $e) {
            if ($e->getCode() === 401) {
                try {
                    // refresh token
                    $newToken = $this->authService->getToken(forceRefresh: true)->token;

                    $response = $this->http->post(
                        $uri,
                        $request->toArray() + ['auth_token' => $newToken]
                    );

                    return WalletPaymentResponseDTO::fromArray($response);
                } catch (Throwable $retryException) {
                    throw PaymobExceptionFactory::fromResponse(
                        ['message' => $retryException->getMessage()],
                        $retryException->getCode(),
                        'wallet'
                    );
                }
            }

            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'wallet'
            );
        }
    }
}
