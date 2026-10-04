<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\Repository;

use JsonException;
use Maatify\Paymob\Authentication\DTO\TokenResponseDTO;
use Maatify\Paymob\Authentication\ValueObject\TokenScope;
use Maatify\Paymob\Exception\TokenStorageException;
use RuntimeException;

final readonly class FileTokenRepository implements TokenRepositoryInterface
{
    public function __construct(private string $directory) {}

    public function get(TokenScope $scope): ?TokenResponseDTO
    {
        return $this->locked($scope, LOCK_SH, function (string $path): ?TokenResponseDTO {
            if (!file_exists($path)) return null;
            $json = file_get_contents($path);
            if ($json === false) throw new TokenStorageException('Unable to read token cache.');
            try { $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { throw new TokenStorageException('Token cache contains malformed JSON.', previous: $e); }
            if (!is_array($data) || !isset($data['token'], $data['profile_id'], $data['issued_at'], $data['expires_at'])
                || !is_string($data['token']) || $data['token'] === '' || !is_int($data['profile_id']) || $data['profile_id'] <= 0
                || !is_int($data['issued_at']) || !is_int($data['expires_at'])) {
                throw new TokenStorageException('Token cache is missing valid required fields.');
            }
            return new TokenResponseDTO($data['token'], $data['profile_id'], $data['issued_at'], $data['expires_at']);
        });
    }

    public function save(TokenScope $scope, TokenResponseDTO $token): void
    {
        $this->locked($scope, LOCK_EX, function (string $path) use ($token): void {
            $json = json_encode(['token' => $token->token, 'profile_id' => $token->profileId, 'issued_at' => $token->issuedAt, 'expires_at' => $token->expiresAt], JSON_THROW_ON_ERROR);
            $temporary = tempnam($this->directory, '.paymob-token-');
            if ($temporary === false) throw new TokenStorageException('Unable to create token cache temporary file.');
            try {
                if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) throw new TokenStorageException('Unable to write complete token cache.');
                if (!chmod($temporary, 0600)) throw new TokenStorageException('Unable to restrict token cache permissions.');
                if (!rename($temporary, $path)) throw new TokenStorageException('Unable to atomically replace token cache.');
            } finally { if (file_exists($temporary) && !unlink($temporary)) throw new TokenStorageException('Unable to remove temporary token cache.'); }
        });
    }

    public function clear(TokenScope $scope): void
    {
        $this->locked($scope, LOCK_EX, static function (string $path): void {
            if (file_exists($path) && !unlink($path)) throw new TokenStorageException('Unable to clear token cache.');
        });
    }

    private function locked(TokenScope $scope, int $mode, callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new TokenStorageException('Unable to create token cache directory.');
        if (!chmod($this->directory, 0700)) throw new TokenStorageException('Unable to restrict token cache directory permissions.');
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $scope->value() . '.json';
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) throw new TokenStorageException('Unable to open token cache lock.');
        try {
            if (!flock($lock, $mode)) throw new TokenStorageException('Unable to lock token cache.');
            try { return $operation($path); }
            finally { if (!flock($lock, LOCK_UN)) throw new TokenStorageException('Unable to unlock token cache.'); }
        } finally { fclose($lock); }
    }
}
