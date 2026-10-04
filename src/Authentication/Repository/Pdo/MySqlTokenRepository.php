<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository\Pdo;

use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\Repository\TokenRepositoryInterface;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Exception\OptionalCapabilityUnavailableException;
use Maatify\Paymob\Exception\TokenStorageException;
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
        if (!is_array($row)) throw new TokenStorageException('Stored Paymob token row is malformed.');

        $token = $row['token'] ?? null;
        $profileIdValue = $row['profile_id'] ?? null;
        $issuedAtValue = $row['issued_at'] ?? null;
        $expiresAtValue = $row['expires_at'] ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new TokenStorageException('Stored Paymob token is malformed.');
        }

        $profileId = self::storedInteger($profileIdValue, 'profile_id');
        $issuedAt = self::storedInteger($issuedAtValue, 'issued_at');
        $expiresAt = self::storedInteger($expiresAtValue, 'expires_at');
        if ($profileId <= 0 || $issuedAt < 0 || $expiresAt <= $issuedAt) {
            throw new TokenStorageException('Stored Paymob token timestamps or profile identity are invalid.');
        }

        return new TokenResponseDTO($token, $profileId, $issuedAt, $expiresAt);
    }

    private static function storedInteger(mixed $value, string $field): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new TokenStorageException("Stored Paymob {$field} is malformed.");
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) throw new TokenStorageException("Stored Paymob {$field} is out of range.");
        return $integer;
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
