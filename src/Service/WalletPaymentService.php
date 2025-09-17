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

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\Payment\WalletPaymentRequestDTO;
use Maatify\Paymob\DTO\Payment\WalletPaymentResponseDTO;
use Maatify\Paymob\Exception\PaymobExceptionFactory;
use Maatify\Paymob\Http\ApiClientInterface;
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
    public function pay(WalletPaymentRequestDTO $request): WalletPaymentResponseDTO
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