<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class Message
{
    /**
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     * @param array<int, string> $attachments
     * @param array<string, mixed>|null $templateVariables
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?int $tenantId = null,
        public readonly ?int $campaignId = null,
        public readonly ?string $providerKey = null,
        public readonly ?string $providerMessageId = null,
        public readonly string $to = '',
        public readonly ?string $recipientName = null,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly string $subject = '',
        public readonly string $html = '',
        public readonly ?string $text = null,
        public readonly ?string $templateKey = null,
        public readonly ?int $templateId = null,
        public readonly ?int $templateVersion = null,
        public readonly ?array $templateVariables = null,
        public readonly array $attachments = [],
        public readonly MessageStatus $status = MessageStatus::QUEUED,
        public readonly DeliveryStatus $deliveryStatus = DeliveryStatus::NONE,
        public readonly ?string $approvedBy = null,
        public readonly ?\DateTimeInterface $approvedAt = null,
        public readonly ?string $rejectedReason = null,
        public readonly ?\DateTimeInterface $scheduledAt = null,
        public readonly int $attempts = 0,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $error = null,
        public readonly string $origin = 'campaign',
        public readonly int $priority = 5,
        public readonly ?array $metadata = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {
    }
}
