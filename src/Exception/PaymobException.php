<?php

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Throwable;

class PaymobException extends SystemMaatifyException implements PaymobExceptionInterface
{
    public function __construct(string $message, int $code = 0, protected array|string|null $response = null, ?Throwable $previous = null, ?int $httpStatus = null)
    {
        parent::__construct($message, $code, $previous, httpStatusOverride: $httpStatus);
    }

    public function getResponse(): array|string|null { return $this->response; }

    protected function defaultErrorCode(): ErrorCodeInterface { return ErrorCodeEnum::MAATIFY_ERROR; }
    protected function defaultCategory(): ErrorCategoryInterface { return ErrorCategoryEnum::SYSTEM; }
    protected function defaultHttpStatus(): int { return 500; }
}
