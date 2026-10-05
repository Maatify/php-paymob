<?php

declare(strict_types=1);

namespace Maatify\Paymob\Order\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use InvalidArgumentException;

final readonly class OrderService
{
    public function __construct(private ApiClientInterface $http, private AuthService $authService) {}

    public function createOrder(CreateOrderCommand $command): OrderResponseDTO
    {
        $uri = '/ecommerce/orders';
        $token = $this->authService->getToken()->token;
        $payload = ['auth_token' => $token, ...$command->toArray()];
        try { $response = $this->http->post($uri, $payload); }
        catch (UnauthorizedException $e) {
            $fresh = $this->authService->getToken(forceRefresh: true)->token;
            $payload = ['auth_token' => $fresh, ...$command->toArray()];
            $response = $this->http->post($uri, $payload);
        }
        foreach (['id' => 'int', 'created_at' => 'string', 'currency' => 'string', 'amount_cents' => 'int'] as $field => $type) {
            if (!isset($response[$field]) || get_debug_type($response[$field]) !== $type) throw new ApiException("Paymob order response has an invalid required field: {$field}.", null, $response);
        }
        if ($response['id'] <= 0 || $response['amount_cents'] <= 0) throw new ApiException('Paymob order response contains a non-positive identifier or amount.', null, $response);
        if (array_key_exists('merchant_order_id', $response) && $response['merchant_order_id'] !== null
            && !is_string($response['merchant_order_id'])) {
            throw new ApiException('Paymob order response has an invalid merchant_order_id.', null, $response);
        }
        if (array_key_exists('items', $response)) {
            if (!is_array($response['items'])) throw new ApiException('Paymob order response has invalid items.', null, $response);
            foreach ($response['items'] as $item) {
                if (!is_array($item)) throw new ApiException('Paymob order response contains a malformed item.', null, $response);
            }
        }
        try { $currency = CurrencyEnum::fromString($response['currency']); }
        catch (InvalidArgumentException $e) { throw new ApiException('Paymob order response contains an unsupported currency.', null, $response, $e); }
        try {
            $items = array_key_exists('items', $response) ? OrderItemCollectionDTO::fromArray($response['items']) : null;
        } catch (ApiException $e) {
            throw new ApiException('Paymob order response contains malformed items.', null, $response, $e);
        }
        return new OrderResponseDTO($response['id'], $response['created_at'], $currency,
            $response['amount_cents'], $response['merchant_order_id'] ?? null, $items, $response);
    }
}
