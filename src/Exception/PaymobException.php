<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:55
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use RuntimeException;

class PaymobException extends RuntimeException
{
    protected ?array $response;

    public function __construct(
        string $message,
        int $code = 0,
        ?array $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }
}