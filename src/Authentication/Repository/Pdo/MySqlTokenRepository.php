<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository\Pdo;

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\Repository\TokenRepositoryInterface;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use PDO;

final readonly class MySqlTokenRepository implements TokenRepositoryInterface
{
    private PDO $pdo;

    public function __construct(mixed $pdo)
    {
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql') || !class_exists(PDO::class)) {
            throw new OptionalCapabilityUnavailableException('MySqlTokenRepository requires ext-pdo and ext-pdo_mysql.');
        }
        if (!$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new OptionalCapabilityUnavailableException('MySqlTokenRepository requires a Host-provided PDO using the mysql driver.');
        }
        $this->pdo = $pdo;
    }

    public function get(TokenScope $scope): ?TokenResponseDTO
    {
        $statement = $this->pdo->prepare('SELECT token, profile_id, issued_at, expires_at FROM maa_paymob_auth_tokens WHERE scope_key = :scope_key LIMIT 1');
        $statement->execute(['scope_key' => $scope->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return new TokenResponseDTO((string) $row['token'], (int) $row['profile_id'], (int) $row['issued_at'], (int) $row['expires_at']);
    }

    public function save(TokenScope $scope, TokenResponseDTO $token): void
    {
        $statement = $this->pdo->prepare('INSERT INTO maa_paymob_auth_tokens (scope_key, token, profile_id, issued_at, expires_at) VALUES (:scope_key, :token, :profile_id, :issued_at, :expires_at) ON DUPLICATE KEY UPDATE token = VALUES(token), profile_id = VALUES(profile_id), issued_at = VALUES(issued_at), expires_at = VALUES(expires_at)');
        $statement->execute(['scope_key' => $scope->value(), 'token' => $token->token, 'profile_id' => $token->profileId,
            'issued_at' => $token->issuedAt, 'expires_at' => $token->expiresAt]);
    }

    public function clear(TokenScope $scope): void
    {
        $statement = $this->pdo->prepare('DELETE FROM maa_paymob_auth_tokens WHERE scope_key = :scope_key');
        $statement->execute(['scope_key' => $scope->value()]);
    }
}
