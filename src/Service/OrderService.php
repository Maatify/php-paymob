<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:43
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Order\OrderResponseDTO;
use Maatify\Paymob\DTO\Order\OrderItemsDTO;
use Maatify\Paymob\DTO\Order\OrderItemDTO;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\OrderException;
use Maatify\Paymob\Exception\PaymobExceptionFactory;
use Maatify\Paymob\Http\ApiClientInterface;
use Throwable;

final readonly class OrderService
{
    public function __construct(
        private ApiClientInterface $http,
        private PaymobConfigDTO $config,
        private AuthService $authService,
    ) {}

    /**
     * Create a new Paymob order
     *
     * @throws OrderException|Throwable
     */
    public function createOrder(OrderRequestDTO $request): OrderResponseDTO
    {
        $uri = "/ecommerce/orders";

        try {
            $response = $this->http->post(
                $uri,
                $request->toArray($this->authService->getToken()->token)
            );

            return $this->mapResponse($response);

        } catch (Throwable $e) {
            // ✅ check if unauthorized
            if ($e->getCode() === 401) {
                try {
                    // refresh token
                    $newToken = $this->authService->getToken(forceRefresh: true)->token;

                    $response = $this->http->post(
                        $uri,
                        $request->toArray($newToken)
                    );

                    return $this->mapResponse($response);
                } catch (Throwable $retryException) {
                    throw PaymobExceptionFactory::fromResponse(
                        ['message' => $retryException->getMessage()],
                        $retryException->getCode(),
                        'order'
                    );
                }
            }

            throw PaymobExceptionFactory::fromResponse(
                ['message' => $e->getMessage()],
                $e->getCode(),
                'order'
            );
        }
    }


    /**
     * Map Paymob API response → DTO
     */
    private function mapResponse(array $response): OrderResponseDTO
    {
        $items = null;
        if (!empty($response['items']) && is_array($response['items'])) {
            $items = OrderItemsDTO::fromArray($response['items']);
        }

        return new OrderResponseDTO(
            id: (int) $response['id'],
            createdAt: $response['created_at'] ?? '',
            currency: CurrencyEnum::fromString($response['currency']),
            amountCents: (int) $response['amount_cents'],
            merchantOrderId: $response['merchant_order_id'] ?? null,
            items: $items,
            row: $response
        );
    }
}
