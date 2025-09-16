<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-06
 * Time: 12:55
 * Project: opay-checkout-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Http;

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