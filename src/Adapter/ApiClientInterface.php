<?php
declare(strict_types=1);

namespace Maatify\Paymob\Adapter;

use Maatify\Paymob\Exception\ApiException;
use Maatify\Paymob\Exception\NetworkException;
use Throwable;

interface ApiClientInterface
{

    /**
     * @throws Throwable
     * @throws NetworkException
     * @throws ApiException
     */
    public function post(string $uri, array $body, array $headers = []): array;

    /**
     * @throws Throwable
     * @throws NetworkException
     * @throws ApiException
     */
    public function get(string $uri, array $query = [], array $headers = []): array;

}