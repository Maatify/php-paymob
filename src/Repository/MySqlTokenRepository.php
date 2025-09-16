<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:48
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Repository;

use Maatify\Paymob\DTO\Auth\TokenResponseDTO;
use PDO;

final class MySqlTokenRepository implements TokenRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function get(): ?TokenResponseDTO
    {
        $stmt = $this->pdo->query("SELECT token, profile_id, issued_at, expires_at FROM paymob_tokens ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return new TokenResponseDTO(
            token: $row['token'],
            profileId: (int)$row['profile_id'],
            issuedAt: (int)$row['issued_at'],
            expiresAt: (int)$row['expires_at']
        );
    }

    public function save(TokenResponseDTO $dto): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO paymob_tokens (token, profile_id, issued_at, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$dto->token, $dto->profileId, $dto->issuedAt, $dto->expiresAt]);
    }

    public function clear(): void
    {
        $this->pdo->exec("DELETE FROM paymob_tokens");
    }
}