<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Contracts;

interface ApprovalAuthorizerInterface
{
    /**
     * @param array<int, int> $messageIds
     */
    public function authorize(mixed $actor, array $messageIds): bool;
}
