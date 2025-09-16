<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:56
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

class ApiException extends PaymobException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        protected ?array $response = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $response, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }
}