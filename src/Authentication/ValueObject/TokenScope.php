<?php

declare(strict_types=1);

namespace Maatify\Paymob\Authentication\ValueObject;

use Maatify\Paymob\Config\PaymobConfig;

final readonly class TokenScope
{
    private function __construct(private string $opaqueValue) {}

    public static function fromConfig(PaymobConfig $config): self
    {
        return new self(hash('sha256', $config->baseUrl . "\0" . $config->apiKey));
    }

    public function value(): string { return $this->opaqueValue; }
}
