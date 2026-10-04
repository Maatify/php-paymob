<?php

declare(strict_types=1);

namespace Maatify\Paymob\Order\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Enum\CurrencyEnum;

final readonly class OrderService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function createOrder(CreateOrderCommand $command): OrderResponseDTO
    {
        $uri = '/ecommerce/orders';
        $token = $this->authService->getToken()->token;
        try { $response = $this->http->post($uri, $command->toArray($token)); }
        catch (ApiException $e) {
            if ($e->getProviderStatusCode() !== 401) throw $e;
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $response = $this->http->post($uri, $command->toArray($fresh));
        }
        foreach (['id' => 'int', 'created_at' => 'string', 'currency' => 'string', 'amount_cents' => 'int'] as $field => $type) {
            if (!isset($response[$field]) || get_debug_type($response[$field]) !== $type) throw new ApiException("Paymob order response has an invalid required field: {$field}.", null, $response);
        }
        if ($response['id'] <= 0 || $response['amount_cents'] <= 0) throw new ApiException('Paymob order response contains a non-positive identifier or amount.', null, $response);
        $items = isset($response['items']) && is_array($response['items']) ? OrderItemCollectionDTO::fromArray($response['items']) : null;
        return new OrderResponseDTO($response['id'], $response['created_at'], CurrencyEnum::fromString($response['currency']),
            $response['amount_cents'], $response['merchant_order_id'] ?? null, $items, $response);
    }
}
