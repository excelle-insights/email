<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Contracts;

use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\StatusContext;
use ExcelleInsights\Email\Entities\StatusResult;

interface MessageStatusServiceInterface
{
    public function change(int $messageId, MessageStatus $to, StatusContext $ctx): StatusResult;

    /**
     * @param array<int, int> $messageIds
     */
    public function changeMany(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult;

    public function changeCampaign(int $campaignId, MessageStatus $to, StatusContext $ctx): BulkStatusResult;

    /**
     * @return array<int, MessageStatus>
     */
    public function allowedTransitions(int $messageId): array;
}
