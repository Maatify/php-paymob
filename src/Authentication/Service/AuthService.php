<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Service;

use Maatify\Paymob\Adapter\ApiClientInterface;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\Repository\TokenRepositoryInterface;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Config\PaymobConfig;
use Maatify\Paymob\Exception\ApiException;
use Maatify\SharedCommon\Contracts\ClockInterface;

final readonly class AuthService
{
    private TokenScope $scope;

    public function __construct(private ApiClientInterface $http, private PaymobConfig $config,
        private TokenRepositoryInterface $repo, private ClockInterface $clock)
    {
        $this->scope = TokenScope::fromConfig($config);
    }

    public function getToken(bool $forceRefresh = false): TokenResponseDTO
    {
        if ($forceRefresh) {
            $this->repo->clear($this->scope);
        } else {
            $existing = $this->repo->get($this->scope);
            if ($existing !== null && $existing->expiresAt > $this->clock->now()->getTimestamp()) return $existing;
        }

        $response = $this->http->post('/auth/tokens', ['api_key' => $this->config->apiKey]);
        if (!isset($response['token'], $response['profile']['id']) || !is_string($response['token']) || $response['token'] === ''
            || !is_int($response['profile']['id']) || $response['profile']['id'] <= 0) {
            throw new ApiException('Paymob Auth response did not satisfy the required token contract.', null, $response);
        }

        $issuedAt = $this->clock->now()->getTimestamp();
        $token = new TokenResponseDTO($response['token'], $response['profile']['id'], $issuedAt, $issuedAt + 3600);
        $this->repo->save($this->scope, $token);
        return $token;
    }
}
