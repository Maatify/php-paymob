<?php
declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Exception\Authentication\AuthenticationMaatifyException;
use Throwable;

class AuthException extends AuthenticationMaatifyException implements PaymobExceptionInterface
{
    use ProviderFailureContextTrait;

    public function __construct(string $message, ?int $providerStatusCode = null, array|string|null $response = null, ?Throwable $previous = null)
    {
        $this->initializeProviderFailureContext($providerStatusCode, $response);
        parent::__construct($message, previous: $previous, errorCodeOverride: ErrorCodeEnum::AUTH_STATE_VIOLATION,
            httpStatusOverride: $providerStatusCode !== null && $providerStatusCode >= 400 && $providerStatusCode < 500 ? $providerStatusCode : null);
    }

    protected function defaultErrorCode(): ErrorCodeInterface { return ErrorCodeEnum::AUTH_STATE_VIOLATION; }
}
