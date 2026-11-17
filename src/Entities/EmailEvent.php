<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class EmailEvent
{
    /**
     * @param array<string, mixed>|null $eventData
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly ?string $messageId = null,
        public readonly ?int $queueId = null,
        public readonly ?int $campaignId = null,
        public readonly string $recipientEmail = '',
        public readonly string $eventType = 'unknown',
        public readonly ?array $eventData = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?\DateTimeInterface $eventTimestamp = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?string $dedupeKey = null,
        public readonly ?string $provider = null,
    ) {
    }
}
