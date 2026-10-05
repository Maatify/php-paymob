<?php

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Throwable;
use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Exceptions\Enum\ErrorCodeEnum;

class ApiException extends PaymobException
{
    public function __construct(string $message, private readonly ?int $providerStatusCode = null,
        array|string|null $response = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $response, $previous,
            $providerStatusCode !== null && $providerStatusCode >= 500 ? $providerStatusCode : null);
    }

    public function getProviderStatusCode(): ?int { return $this->providerStatusCode; }
    public function getStatusCode(): ?int { return $this->providerStatusCode; }

    protected function defaultErrorCode(): ErrorCodeInterface { return ErrorCodeEnum::MAATIFY_ERROR; }
    protected function defaultCategory(): ErrorCategoryInterface { return ErrorCategoryEnum::SYSTEM; }
    protected function defaultHttpStatus(): int { return 500; }
}
