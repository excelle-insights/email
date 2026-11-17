<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class SendRequest
{
    /**
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     * @param array<int, string> $attachments
     * @param array<string, mixed> $templateVars
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
        public readonly ?string $recipientName = null,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $attachments = [],
        public readonly ?string $templateKey = null,
        public readonly array $templateVars = [],
        public readonly ?int $tenantId = null,
        public readonly ?string $providerKey = null,
        public readonly ?int $campaignId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?\DateTimeInterface $scheduledAt = null,
        public readonly array $metadata = [],
        public readonly int $priority = 5,
        public readonly string $origin = 'campaign',
        public readonly array $options = [],
    ) {
    }
}
