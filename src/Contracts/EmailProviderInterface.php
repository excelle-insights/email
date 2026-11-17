<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Contracts;

use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;

interface EmailProviderInterface
{
    /**
     * Unique provider key used in the registry and database.
     */
    public function getKey(): string;

    /**
     * Human-readable provider name.
     */
    public function getDisplayName(): string;

    /**
     * Describes what this provider supports.
     */
    public function capabilities(): ProviderCapabilities;

    /**
     * Send a single email.
     */
    public function send(SendRequest $request): SendResult;

    /**
     * Parse a provider webhook payload into a normalised event.
     *
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent;
}
