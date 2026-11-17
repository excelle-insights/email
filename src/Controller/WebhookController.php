<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Controller;

use ExcelleInsights\Email\Entities\WebhookResult;
use ExcelleInsights\Email\Services\WebhookService;

final class WebhookController
{
    public function __construct(private readonly WebhookService $webhookService)
    {
    }

    /**
     * Verify a provider webhook without recording an event.
     *
     * @param array<string, mixed> $requestData
     */
    public function verify(string $provider, array $requestData, string $payload): bool
    {
        return $this->webhookService->verify($provider, $requestData, $payload);
    }

    /**
     * Verify, parse, deduplicate and process a provider webhook.
     *
     * @param array<string, mixed> $requestData
     */
    public function process(string $provider, array $requestData, string $payload): WebhookResult
    {
        return $this->webhookService->process($provider, $requestData, $payload);
    }
}
