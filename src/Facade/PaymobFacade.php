<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-17
 * Time: 16:15
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Facade;

use Maatify\Paymob\DTO\Auth\TokenResponseDTO;
use Maatify\Paymob\DTO\KioskFlowResultDTO;
use Maatify\Paymob\DTO\Order\OrderRequestDTO;
use Maatify\Paymob\DTO\Order\OrderResponseDTO;
use Maatify\Paymob\DTO\Payment\BillingDataDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentRequestDTO;
use Maatify\Paymob\DTO\Payment\KioskPaymentResponseDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyRequestDTO;
use Maatify\Paymob\DTO\Payment\PaymentKeyResponseDTO;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\Exception\{ApiException, AuthException, NetworkException, OrderException, TransactionException};
use Maatify\Paymob\Http\ApiClientInterface;
use Maatify\Paymob\Repository\TokenRepositoryInterface;
use Maatify\Paymob\Service\{AuthService, KioskPaymentService, OrderService, PaymentKeyService};
use Psr\Log\LoggerInterface;
use Throwable;

final class PaymobFacade
{
    private AuthService $auth;
    private OrderService $orders;
    private PaymentKeyService $keys;
    private KioskPaymentService $kiosk;

    public function __construct(
        private readonly PaymobConfigDTO $config,
        private readonly ApiClientInterface $http,
        private readonly TokenRepositoryInterface $repo,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $channel = 'paymob.facade.kiosk'
    ) {
        $this->auth   = new AuthService($this->http, $this->config, $this->repo);
        $this->orders = new OrderService($this->http, $this->config, $this->auth);
        $this->keys   = new PaymentKeyService($this->http, $this->auth);
        $this->kiosk  = new KioskPaymentService($this->http, $this->auth);
    }

    /**
     * Full kiosk payment flow (Order → Key → Pay).
     *
     * @param   OrderRequestDTO  $orderRequest
     * @param   BillingDataDTO   $billing
     *
     * @return KioskFlowResultDTO
     * @throws AuthException|OrderException|TransactionException|NetworkException|ApiException|Throwable
     */
    public function payViaKiosk(OrderRequestDTO $orderRequest, BillingDataDTO $billing): KioskFlowResultDTO
    {
        $this->logger?->info("[{$this->channel}] Starting payViaKiosk flow");

        try {
            // 0. Prefetch Auth
            /** @var TokenResponseDTO $token */
            $token = $this->auth->getToken();
            $this->logger?->info("[{$this->channel}.auth] Token retrieved", [
                'token_hash' => substr(sha1($token->token), 0, 12)
            ]);

            // 1. Create Order
            /** @var OrderResponseDTO $order */
            $order = $this->orders->createOrder($orderRequest);
            $this->logger?->info("[{$this->channel}.order] Order created", ['id' => $order->id]);

            // 2. Generate Payment Key
            $keyRequest = new PaymentKeyRequestDTO(
                orderId      : $order->id,
                integrationId: $this->config->integrationIdKiosk,
                amountCents  : $order->amountCents,
                currency     : $order->currency,
                billingData  : $billing
            );

            /** @var PaymentKeyResponseDTO $paymentKey */
            $paymentKey = $this->keys->generate($keyRequest);
            $this->logger?->info("[{$this->channel}.key] Payment key generated");

            // 3. Pay via Kiosk
            /** @var KioskPaymentResponseDTO $kiosk */
            $kiosk = $this->kiosk->pay(
                new KioskPaymentRequestDTO($paymentKey->token)
            );
            $this->logger?->info("[{$this->channel}.payment] Kiosk payment initiated", [
                'transaction_id' => $kiosk->transactionId,
                'bill_ref'       => $kiosk->billReference
            ]);

            return new KioskFlowResultDTO($order, $kiosk);

        } catch (Throwable $e) {
            $this->logger?->error("[{$this->channel}] payViaKiosk failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}