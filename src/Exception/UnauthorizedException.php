<?php

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Maatify\Exceptions\Exception\Authentication\UnauthorizedMaatifyException;
use Throwable;

class UnauthorizedException extends UnauthorizedMaatifyException implements PaymobExceptionInterface
{
    use ProviderFailureContextTrait;

    public function __construct(string $message, ?int $providerStatusCode = null, array|string|null $response = null, ?Throwable $previous = null)
    {
        $this->initializeProviderFailureContext($providerStatusCode, $response);
        parent::__construct($message, previous: $previous,
            httpStatusOverride: $providerStatusCode !== null && $providerStatusCode >= 400 && $providerStatusCode < 500 ? $providerStatusCode : null);
    }
}
