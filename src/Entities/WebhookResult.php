<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

final class WebhookResult
{
    public const RECEIVED = 'received';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';

    public function __construct(
        public readonly string $status,
        public readonly ?int $eventId = null,
        public readonly ?int $messageId = null,
        public readonly ?int $queueId = null,
        public readonly ?int $campaignId = null,
        public readonly ?string $eventType = null,
    ) {
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::DUPLICATE;
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }
}
