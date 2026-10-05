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
use Maatify\Paymob\Exception\PaymobExceptionInterface;
use Maatify\Paymob\Exception\RateLimitException;
use Maatify\Paymob\Exception\ServiceUnavailableException;
use Maatify\Paymob\Exception\TransactionException;
use Maatify\Paymob\Exception\UnauthorizedException;
use Maatify\Paymob\Exception\ValidationException;

final class PaymobExceptionFactory
{
    /**
     * $resp: Full response payload returned by Paymob.
     * $httpStatus: HTTP status when available.
     * $context: Operation context such as 'auth', 'order', or 'transaction'.
     */
    public static function fromResponse(
        array $resp,
        ?int $httpStatus = null,
        string $context = 'api'
    ): PaymobExceptionInterface {
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
            // HTTP status semantics take precedence over contradictory provider body codes.
            $httpStatus === 401 => new UnauthorizedException($message, $httpStatus, $resp),
            $httpStatus === 404 => new NotFoundException($message, $httpStatus, $resp),
            $httpStatus === 429 => new RateLimitException($message, $httpStatus, $resp),
            $httpStatus !== null && $httpStatus >= 500 => new ServiceUnavailableException($message, $httpStatus, $resp),

            // Provider body codes refine otherwise compatible responses.
            in_array($normalized, ['unauthorized', 'invalid_api_key', 'auth_failed']) =>
            new UnauthorizedException($message, $httpStatus, $resp),

            // Validation errors (bad params)
            in_array($normalized, ['validation_failed', 'invalid_params', 'missing_params']) =>
            new ValidationException($message, $httpStatus, $resp),

            // Not found
            in_array($normalized, ['not_found', 'order_not_found', 'transaction_not_found']) =>
            new NotFoundException($message, $httpStatus, $resp),

            // Duplicate reference, such as an already-used integration ID or order ID.
            in_array($normalized, ['duplicate_reference', 'reference_exists']) =>
            new DuplicateReferenceException($message, $httpStatus, $resp),

            // Use the exception type associated with the operation context.
            $context === 'auth' =>
            new AuthException($message, $httpStatus, $resp),
            $context === 'order' =>
            new OrderException($message, $httpStatus, $resp),
            $context === 'transaction' =>
            new TransactionException($message, $httpStatus, $resp),
            default =>
            new ApiException($message, $httpStatus, $resp),
        };
    }
}
