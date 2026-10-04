<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:47
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\DTO;

readonly class TokenResponseDTO
{
    public function __construct(
        public string $token,
        public int $profileId,
        public int $issuedAt,  // timestamp
        public int $expiresAt  // timestamp
    )
    {
    }
    public function jsonSerialize(): array { return get_object_vars($this); }
}