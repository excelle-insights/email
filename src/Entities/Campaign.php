<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class Campaign
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly string $name = '',
        public readonly ?string $provider = null,
        public readonly ?int $templateId = null,
        public readonly ?string $subject = null,
        public readonly int $totalRecipients = 0,
        public readonly int $queuedCount = 0,
        public readonly int $sentCount = 0,
        public readonly int $deliveredCount = 0,
        public readonly int $failedCount = 0,
        public readonly int $openedCount = 0,
        public readonly int $clickedCount = 0,
        public readonly int $bouncedCount = 0,
        public readonly string $status = self::STATUS_DRAFT,
        public readonly ?\DateTimeInterface $sendDate = null,
        public readonly ?array $metadata = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {
    }
}
