<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository;

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;

final class InMemoryTokenRepository implements TokenRepositoryInterface
{
    /** @var array<string, TokenResponseDTO> */
    private array $tokens = [];
    public function get(TokenScope $scope): ?TokenResponseDTO { return $this->tokens[$scope->value()] ?? null; }
    public function save(TokenScope $scope, TokenResponseDTO $token): void { $this->tokens[$scope->value()] = $token; }
    public function clear(TokenScope $scope): void { unset($this->tokens[$scope->value()]); }
}
