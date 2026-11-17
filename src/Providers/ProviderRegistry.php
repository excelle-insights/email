<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Exceptions\ProviderNotFoundException;

class ProviderRegistry
{
    /**
     * @var array<string, EmailProviderInterface>
     */
    private array $providers = [];

    public function register(EmailProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    public function get(string $key): EmailProviderInterface
    {
        if (! isset($this->providers[$key])) {
            throw new ProviderNotFoundException($key);
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return array<string, EmailProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
