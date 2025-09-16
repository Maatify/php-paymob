<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 16:46
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\Repository;

use Maatify\Paymob\DTO\Auth\TokenResponseDTO;

interface TokenRepositoryInterface
{
    public function get(): ?TokenResponseDTO;
    public function save(TokenResponseDTO $dto): void;
    public function clear(): void;
}