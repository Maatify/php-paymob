<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:50
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Repository;

use Maatify\Paymob\DTO\Auth\TokenResponseDTO;

final class FileTokenRepository implements TokenRepositoryInterface
{
    public function __construct(private string $path) {}

    public function get(): ?TokenResponseDTO
    {
        if (!file_exists($this->path)) return null;
        $data = json_decode(file_get_contents($this->path), true);
        if (!$data) return null;

        return new TokenResponseDTO(
            token: $data['token'],
            profileId: (int)$data['profile_id'],
            issuedAt: (int)$data['issued_at'],
            expiresAt: (int)$data['expires_at']
        );
    }

    public function save(TokenResponseDTO $dto): void
    {
        file_put_contents($this->path, json_encode([
            'token'      => $dto->token,
            'profile_id' => $dto->profileId,
            'issued_at'  => $dto->issuedAt,
            'expires_at' => $dto->expiresAt,
        ], JSON_PRETTY_PRINT));
    }

    public function clear(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}