<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:49
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository;

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;

final class InMemoryTokenRepository implements TokenRepositoryInterface
{
    private ?TokenResponseDTO $token = null;

    public function get(): ?TokenResponseDTO
    {
        return $this->token;
    }

    public function save(TokenResponseDTO $dto): void
    {
        $this->token = $dto;
    }

    public function clear(): void
    {
        $this->token = null;
    }
}