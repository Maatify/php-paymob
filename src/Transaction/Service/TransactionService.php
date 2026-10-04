<?php

declare(strict_types=1);

namespace Maatify\Paymob\Transaction\Service;

use InvalidArgumentException;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Transaction\DTO\TransactionResponseDTO;

final readonly class TransactionService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function getTransaction(int $id): TransactionResponseDTO
    {
        if ($id <= 0) throw new InvalidArgumentException('Transaction ID must be positive.');
        $uri = "/acceptance/transactions/{$id}";
        $token = $this->authService->getToken()->token;
        try { $response = $this->http->get($uri, headers: ['Authorization' => 'Bearer ' . $token]); }
        catch (ApiException $e) {
            if ($e->getProviderStatusCode() !== 401) throw $e;
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $response = $this->http->get($uri, headers: ['Authorization' => 'Bearer ' . $fresh]);
        }
        return TransactionResponseDTO::fromArray($response);
    }
}
