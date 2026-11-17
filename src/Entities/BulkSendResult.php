<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class BulkSendResult
{
    /**
     * @param array<int, array<string, mixed>> $failures
     */
    public function __construct(
        public readonly ?int $campaignId,
        public readonly int $total,
        public readonly int $queued,
        public readonly int $failed,
        public readonly array $failures = [],
    ) {
    }
}
