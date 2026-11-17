<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

final class CampaignAnalytics
{
    /**
     * @param array<string, int> $eventsByType
     * @param array<string, int> $messagesByStatus
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly int $totalRecipients,
        public readonly int $queuedCount,
        public readonly int $sentCount,
        public readonly int $deliveredCount,
        public readonly int $failedCount,
        public readonly int $openedCount,
        public readonly int $clickedCount,
        public readonly int $bouncedCount,
        public readonly array $eventsByType,
        public readonly array $messagesByStatus,
        public readonly float $deliveryRate,
        public readonly float $openRate,
        public readonly float $clickRate,
    ) {
    }
}
