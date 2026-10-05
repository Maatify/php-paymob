<?php

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

/** @internal Retains provider response evidence for specialized exceptions. */
trait ProviderFailureContextTrait
{
    private ?int $providerStatusCode = null;
    private array|string|null $providerResponse = null;

    protected function initializeProviderFailureContext(
        ?int $providerStatusCode,
        array|string|null $response,
    ): void {
        $this->providerStatusCode = $providerStatusCode;
        $this->providerResponse = $response;
    }

    public function getProviderStatusCode(): ?int { return $this->providerStatusCode; }
    public function getStatusCode(): ?int { return $this->providerStatusCode; }
    public function getResponse(): array|string|null { return $this->providerResponse; }
}
