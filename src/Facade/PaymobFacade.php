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

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Payment\DTO\KioskFlowResultDTO;
use Maatify\Paymob\Order\Command\CreateOrderCommand;
use Maatify\Paymob\Order\DTO\OrderResponseDTO;
use Maatify\Paymob\Payment\ValueObject\BillingData;
use Maatify\Paymob\Payment\Command\InitiateKioskPaymentCommand;
use Maatify\Paymob\Payment\DTO\KioskPaymentResponseDTO;
use Maatify\Paymob\Payment\Command\GeneratePaymentKeyCommand;
use Maatify\Paymob\Payment\DTO\PaymentKeyResponseDTO;
use Maatify\Paymob\Payment\Command\InitiateWalletPaymentCommand;
use Maatify\Paymob\Payment\DTO\WalletPaymentResponseDTO;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\{ApiException, AuthException, NetworkException, OrderException, TransactionException};
use Maatify\Paymob\Payment\DTO\WalletFlowResultDTO;
use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\Repository\TokenRepositoryInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Paymob\Authentication\Service\AuthService;
use Maatify\Paymob\Order\Service\OrderService;
use Maatify\Paymob\Payment\Service\KioskPaymentService;
use Maatify\Paymob\Payment\Service\PaymentKeyService;
use Maatify\Paymob\Payment\Service\WalletPaymentService;
use Psr\Log\LoggerInterface;
use Throwable;

final class PaymobFacade
{
    private AuthService $auth;
    private OrderService $orders;
    private PaymentKeyService $keys;
    private KioskPaymentService $kiosk;
    private WalletPaymentService $wallet;

    public function __construct(
        private readonly PaymobConfig $config,
        private readonly ApiClientInterface $http,
        private readonly TokenRepositoryInterface $repo,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $channel = 'paymob.facade.kiosk'
    ) {
        $this->auth   = new AuthService($this->http, $this->config, $this->repo, $this->clock);
        $this->orders = new OrderService($this->http, $this->config, $this->auth);
        $this->keys   = new PaymentKeyService($this->http, $this->auth);
        $this->kiosk  = new KioskPaymentService($this->http, $this->auth);
        $this->wallet = new WalletPaymentService($this->http, $this->auth);
    }

    /**
     * Full kiosk payment flow (Order → Key → Pay).
     *
     * @param   CreateOrderCommand  $orderRequest
     * @param   BillingData   $billing
     *
     * @return KioskFlowResultDTO
     * @throws AuthException|OrderException|TransactionException|NetworkException|ApiException|Throwable
     */
    public function payViaKiosk(CreateOrderCommand $orderRequest, BillingData $billing): KioskFlowResultDTO
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
            $keyRequest = new GeneratePaymentKeyCommand(
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
                new InitiateKioskPaymentCommand($paymentKey->token)
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

    /**
     * Full Wallet payment flow (Order → Key → Wallet Pay).
     *
     * @param CreateOrderCommand $orderRequest
     * @param BillingData  $billing
     * @param string          $walletNumber  Mobile wallet MSISDN (e.g., "2010xxxxxxx")
     *
     * @return WalletFlowResultDTO
     * @throws AuthException|OrderException|TransactionException|NetworkException|ApiException|Throwable
     */
    public function payViaWallet(CreateOrderCommand $orderRequest, BillingData $billing, string $walletNumber): WalletFlowResultDTO
    {
        $this->logger?->info("[{$this->channel}] Starting payViaWallet flow");

        // 0. Prefetch Auth
        /** @var TokenResponseDTO $token */
        $token = $this->auth->getToken();
        $this->logger?->info("[{$this->channel}] Auth token retrieved");

        // 1. Create Order
        /** @var OrderResponseDTO $order */
        $order = $this->orders->createOrder($orderRequest);
        $this->logger?->info("[{$this->channel}] Order created", ['id' => $order->id]);

        // 2. Generate Payment Key
        $keyRequest = new GeneratePaymentKeyCommand(
            orderId      : $order->id,
            integrationId: $this->config->integrationIdWallet,
            amountCents  : $order->amountCents,
            currency     : $order->currency,
            billingData  : $billing
        );
        /** @var PaymentKeyResponseDTO $paymentKey */
        $paymentKey = $this->keys->generate($keyRequest);
        $this->logger?->info("[{$this->channel}] Payment key generated");

        // 3. Wallet Payment
        /** @var WalletPaymentResponseDTO $wallet */
        $wallet = $this->wallet->pay(
            new InitiateWalletPaymentCommand(
                paymentToken: $paymentKey->token,
                phoneNumber: $walletNumber
            )
        );
        $this->logger?->info("[{$this->channel}] Wallet payment initiated", [
            'transaction_id' => $wallet->transactionId,
            'redirect_url'   => $wallet->redirectUrl
        ]);

        return new WalletFlowResultDTO($order, $wallet);
    }
}
