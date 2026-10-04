<?php

declare(strict_types=1);

namespace Maatify\Paymob\Adapter;

use JsonException;
use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Factory\PaymobExceptionFactory;

/** @internal Shared built-in transport response classification. */
final class ResponseDecoder
{
    public static function decode(int $status, string $body): array
    {
        try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) {
            if ($status >= 400) self::throwProviderFailure($status, $body);
            throw new ApiException('Paymob returned malformed JSON.', null, $body, $e);
        }
        if (!is_array($decoded)) {
            if ($status >= 400) self::throwProviderFailure($status, $body);
            throw new ApiException('Paymob returned a JSON value that is not an object or array.', null, $body);
        }
        if ($status >= 400) self::throwProviderFailure($status, $decoded);
        return $decoded;
    }

    private static function throwProviderFailure(int $status, array|string $body): never
    {
        if (is_array($body)) throw PaymobExceptionFactory::fromResponse($body, $status);
        $message = 'Paymob provider request failed.';
        throw match (true) {
            $status === 401 => new UnauthorizedException($message, $status, $body),
            $status === 404 => new NotFoundException($message, $status, $body),
            $status === 429 => new RateLimitException($message, $status, $body),
            $status >= 500 => new ServiceUnavailableException($message, $status, $body),
            default => new ApiException($message, $status, $body),
        };
    }
}
