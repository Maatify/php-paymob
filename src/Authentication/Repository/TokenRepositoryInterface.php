<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository;

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;

interface TokenRepositoryInterface
{
    public function get(TokenScope $scope): ?TokenResponseDTO;
    public function save(TokenScope $scope, TokenResponseDTO $token): void;
    public function clear(TokenScope $scope): void;
}
