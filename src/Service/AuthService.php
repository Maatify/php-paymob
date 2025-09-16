<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:53
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Service;

use Maatify\Paymob\DTO\Auth\TokenResponseDTO;
use Maatify\Paymob\DTO\PaymobConfigDTO;
use Maatify\Paymob\Exception\AuthException;
use Maatify\Paymob\Exception\NetworkException;
use Maatify\Paymob\Exception\PaymobExceptionFactory;
use Maatify\Paymob\Http\ApiClientInterface;
use Maatify\Paymob\Repository\TokenRepositoryInterface;

final readonly class AuthService
{
    public function __construct(
        private ApiClientInterface $http,
        private PaymobConfigDTO $config,
        private TokenRepositoryInterface $repo
    ) {}

    public function getToken(): TokenResponseDTO
    {
        try {
            $uri = '/auth/tokens';
            // 1. تحقق من وجود توكن مخزن وصالح
            $existing = $this->repo->get();
            if ($existing && $existing->expiresAt > time()) {
                return $existing;
            }

            // 2. اطلب توكن جديد من Paymob
            $response = $this->http->post(
                $uri,
                ['api_key' => $this->config->apiKey]
            );

            if (!isset($response['token'], $response['profile']['id'])) {
                throw PaymobExceptionFactory::fromResponse($response, context: 'auth');
            }

            // 3. بناء DTO
            $dto = new TokenResponseDTO(
                token: $response['token'],
                profileId: (int)$response['profile']['id'],
                issuedAt: time(),
                expiresAt: time() + ((int)($_ENV['PAYMOB_KEYS_EXPIRY'] ?? 180) * 60)
            );

            // 4. تحديث الـ Repository
            $this->repo->clear();
            $this->repo->save($dto);

            return $dto;
        } catch (\Throwable $e) {
            // لو ده خطأ شبكة
            if ($e instanceof \RuntimeException) {
                throw new NetworkException(
                    'Network error while calling Paymob Auth API: ' . $e->getMessage(),
                    $e->getCode(),
                    previous: $e
                );
            }

            // أي خطأ آخر → AuthException
            throw new AuthException(
                'Failed to generate Paymob token: ' . $e->getMessage(),
                $e->getCode(),
                previous: $e
            );
        }
    }
}

