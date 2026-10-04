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

namespace Maatify\Paymob\Order\Service;

use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Order\DTO\OrderItemCollectionDTO;
use Maatify\Paymob\Order\DTO\OrderItemDTO;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Enum\CurrencyEnum;
use Maatify\Paymob\Exception\OrderException;
use Maatify\Paymob\Factory\PaymobExceptionFactory;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Throwable;

final readonly class OrderService
{
    public function __construct(
        private ApiClientInterface $http,
        private PaymobConfig $config,
        private AuthService $authService,
    ) {}

    /**
     * Create a new Paymob order
     *
     * @throws OrderException|Throwable
     */
    public function createOrder(CreateOrderCommand $request): OrderResponseDTO
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
            $items = OrderItemCollectionDTO::fromArray($response['items']);
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
