<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 20:29
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\Payment\PaymentKeyResponseDTO;
use Maatify\Paymob\DTO\Transaction\TransactionResponseDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\PaymobExceptionFactory;
use Maatify\Paymob\Http\ApiClientInterface;
use Throwable;

final readonly class TransactionService
{
    public function __construct(
        private ApiClientInterface $http,
        private AuthService $authService
    )
    {
    }

    /**
     * Retrieve single transaction by ID
     *
     * @throws Throwable
     */
    public function getTransaction(int $id): TransactionResponseDTO
    {
        $uri = "/acceptance/transactions/{$id}";

        try {
            $authToken = $this->authService->getToken()->token;

            $response = $this->http->get(
                uri: $uri,
                headers: ['Authorization' => $authToken]
            );

            return $this->mapResponse($response);
        } catch (Throwable $e) {
            // handle unauthorized → retry with fresh token
            if ($e->getCode() === 401) {
                $newToken = $this->authService->getToken(forceRefresh: true)->token;

                $response = $this->http->get(
                    uri: $uri,
                    headers: ['Authorization' => $newToken]
                );

                return $this->mapResponse($response);
            }
            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'transaction'
            );
        }
    }

    private function mapResponse(array $response): TransactionResponseDTO
    {
        return new TransactionResponseDTO(
            id              : (int)$response['id'],
            orderId         : (int)$response['order']['id'],
            amountCents     : (int)$response['amount_cents'],
            currency        : CurrencyEnum::from($response['currency']),
            success         : (bool)$response['success'],
            pending         : (bool)$response['pending'],
            hmac            : $response['hmac'] ?? null,
            paymentKeyClaims: $response['payment_key_claims'] ?? null,
            errorCode       : $response['error_occured'] ? ($response['data']['gateway_integration_pk'] ?? null) : null,
            errorMessage    : $response['data']['message'] ?? null,
            createdAt       : $response['created_at'] ?? null,
        );
    }
}