<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Support;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;

final class RecordingProvider implements EmailProviderInterface
{
    /**
     * @var array<int, SendRequest>
     */
    public array $requests = [];

    public function __construct(
        private readonly string $key = 'record',
        private readonly ?SendResult $result = null,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getDisplayName(): string
    {
        return 'Recording provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(bulk: true, cc: true, bcc: true, tracking: true, webhookEvents: true);
    }

    public function send(SendRequest $request): SendResult
    {
        $this->requests[] = $request;

        return $this->result ?? new SendResult(
            status: \ExcelleInsights\Email\Entities\MessageStatus::SENT,
            providerMessageId: 'provider-' . count($this->requests),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent
    {
        return null;
    }
}
