<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 17:07
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Factory;

use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\DuplicateReferenceException;
use Maatify\Paymob\Exception\NotFoundException;
use Maatify\Paymob\Exception\OrderException;
use Maatify\Paymob\Exception\PaymobException;
use Maatify\Paymob\Exception\RateLimitException;
use Maatify\Paymob\Exception\ServiceUnavailableException;
use Maatify\Paymob\Exception\TransactionException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Exception\ValidationException;

final class PaymobExceptionFactory
{
    /**
     * $resp: الرد الكامل اللي رجعه Paymob
     * $httpStatus: كود HTTP إذا متاح
     * $context: مثلا 'auth', 'order', 'transaction'
     */
    public static function fromResponse(
        array $resp,
        ?int $httpStatus = null,
        string $context = 'api'
    ): PaymobException {
        $errorCode = (string)($resp['status']
                              ?? $resp['error_code']
                                 ?? $resp['code']
                                    ?? '');
        $message = $resp['detail']
                   ?? $resp['message']
                      ?? 'Unknown error from Paymob';

        // Normalize code maybe
        $normalized = strtolower($errorCode);

        return match (true) {
            // Unauthorized / missing API key
            $httpStatus === 401
            || in_array($normalized, ['unauthorized', 'invalid_api_key', 'auth_failed']) =>
            new UnauthorizedException($message, $httpStatus ?? 0, $resp),

            // Validation errors (bad params)
            in_array($normalized, ['validation_failed', 'invalid_params', 'missing_params']) =>
            new ValidationException($message, $httpStatus ?? 0, $resp),

            // Not found
            in_array($normalized, ['not_found', 'order_not_found', 'transaction_not_found']) =>
            new NotFoundException($message, $httpStatus ?? 0, $resp),

            // Duplicate Reference (مثلاً لو integration ID مستخدم أو orderId)
            in_array($normalized, ['duplicate_reference', 'reference_exists']) =>
            new DuplicateReferenceException($message, $httpStatus ?? 0, $resp),

            // Rate limit / Too many requests
            $httpStatus === 429 =>
            new RateLimitException($message, $httpStatus ?? 0, $resp),

            // Service unavailable / internal server error
            $httpStatus >= 500 =>
            new ServiceUnavailableException($message, $httpStatus ?? 0, $resp),

            // Default حسب الـ context
            $context === 'auth' =>
            new AuthException($message, $httpStatus ?? 0, $resp),
            $context === 'order' =>
            new OrderException($message, $httpStatus ?? 0, $resp),
            $context === 'transaction' =>
            new TransactionException($message, $httpStatus ?? 0, $resp),
            default =>
            new ApiException($message, $httpStatus ?? 0, $resp),
        };
    }
}
