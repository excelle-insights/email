<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Exceptions\NoDefaultSenderException;
use ExcelleInsights\Email\Repositories\SenderRepository;

class ProviderResolver
{
    public function __construct(
        private readonly SenderRepository $senderRepository,
        private readonly EmailConfig $config,
    ) {
    }

    /**
     * Resolve the sender/provider to use for a send.
     *
     * Resolution order:
     * 1. Explicitly requested provider.
     * 2. Tenant default sender.
     * 3. Global default sender.
     * 4. `.env` bootstrap sender, then provider.
     *
     * @throws NoDefaultSenderException
     */
    public function resolve(?string $explicitProvider, ?int $tenantId): string
    {
        if ($explicitProvider !== null && $explicitProvider !== '') {
            return $explicitProvider;
        }

        $tenantDefault = $this->senderRepository->getDefaultSender($tenantId);
        if ($tenantDefault !== null && $tenantDefault !== '') {
            return $tenantDefault;
        }

        $globalDefault = $this->senderRepository->getDefaultSender(null);
        if ($globalDefault !== null && $globalDefault !== '') {
            return $globalDefault;
        }

        $envSender = $this->config->defaultSender();
        if ($envSender !== null && $envSender !== '') {
            return $envSender;
        }

        $envProvider = $this->config->defaultProvider();
        if ($envProvider !== null && $envProvider !== '') {
            return $envProvider;
        }

        throw new NoDefaultSenderException();
    }
}
