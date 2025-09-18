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

use Maatify\Paymob\DTO\Transaction\TransactionResponseDTO;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\PaymobExceptionFactory;
use Maatify\Paymob\Exception\TransactionException;
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
     * Get transaction details by ID.
     *
     * @param   int  $id
     *
     * @return TransactionResponseDTO
     * @throws TransactionException|NetworkException|ApiException
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

            return TransactionResponseDTO::fromArray($response);
        } catch (Throwable $e) {
            // handle unauthorized → retry with fresh token
            if ($e->getCode() === 401) {
                $newToken = $this->authService->getToken(forceRefresh: true)->token;

                $response = $this->http->get(
                    uri: $uri,
                    headers: ['Authorization' => $newToken]
                );

                return TransactionResponseDTO::fromArray($response);
            }
            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'transaction'
            );
        }
    }
}