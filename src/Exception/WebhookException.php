<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:57
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\Security\SecurityMaatifyException;
use Throwable;

class WebhookException extends SecurityMaatifyException implements PaymobExceptionInterface
{
    protected array|string|null $response;

    public function __construct(string $message, int $code = 0, array|string|null $response = null, ?Throwable $previous = null, ?int $httpStatus = null)
    {
        $this->response = $response;
        parent::__construct($message, $code, $previous, httpStatusOverride: $httpStatus === 403 ? 403 : null);
    }

    public function getResponse(): array|string|null { return $this->response; }
    protected function defaultErrorCode(): ErrorCodeInterface { return ErrorCodeEnum::MAATIFY_ERROR; }
}
