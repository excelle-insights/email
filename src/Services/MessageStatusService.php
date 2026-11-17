<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Contracts\ApprovalAuthorizerInterface;
use ExcelleInsights\Email\Contracts\BulkStatusResult;
use ExcelleInsights\Email\Contracts\MessageStatusServiceInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\StatusContext;
use ExcelleInsights\Email\Entities\StatusResult;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use ExcelleInsights\Email\Repositories\StatusChangeRepository;

class MessageStatusService implements MessageStatusServiceInterface
{
    public function __construct(
        private readonly QueueRepository $queueRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly StatusChangeRepository $statusChangeRepository,
        private readonly StatusTransitionPolicy $policy,
        private readonly ?ApprovalAuthorizerInterface $authorizer = null,
    ) {
    }

    public function change(int $messageId, MessageStatus $to, StatusContext $ctx): StatusResult
    {
        return $this->performChange($messageId, $to, $ctx, StatusTransitionPolicy::SOURCE_APPLICATION);
    }

    /**
     * @param array<int, int> $messageIds
     */
    public function changeMany(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult
    {
        if ($ctx->atomic) {
            return $this->queueRepository->transaction(
                fn (): BulkStatusResult => $this->collectChanges($messageIds, $to, $ctx),
            );
        }

        return $this->collectChanges($messageIds, $to, $ctx);
    }

    public function changeCampaign(int $campaignId, MessageStatus $to, StatusContext $ctx): BulkStatusResult
    {
        if ($to === MessageStatus::CANCELLED) {
            $this->campaignRepository->setStatus($campaignId, 'cancelled');
        }

        return $this->changeMany($this->queueRepository->findIdsByCampaignId($campaignId), $to, $ctx);
    }

    /**
     * @return array<int, MessageStatus>
     */
    public function allowedTransitions(int $messageId): array
    {
        $message = $this->queueRepository->findById($messageId);

        if ($message === null) {
            return [];
        }

        return $this->policy->allowedTransitions($message->status, StatusTransitionPolicy::SOURCE_APPLICATION);
    }

    public function approve(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::QUEUED, $ctx);
    }

    public function reject(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::REJECTED, $ctx);
    }

    public function cancel(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::CANCELLED, $ctx);
    }

    public function reschedule(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::SCHEDULED, $ctx);
    }

    public function retry(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::QUEUED, $ctx);
    }

    public function resubmit(int $messageId, StatusContext $ctx): StatusResult
    {
        return $this->change($messageId, MessageStatus::PENDING, $ctx);
    }

    public function workerChange(int $messageId, MessageStatus $to): StatusResult
    {
        return $this->performChange(
            $messageId,
            $to,
            new StatusContext(actor: 'worker'),
            StatusTransitionPolicy::SOURCE_WORKER,
        );
    }

    /**
     * @param array<int, int> $messageIds
     */
    private function collectChanges(array $messageIds, MessageStatus $to, StatusContext $ctx): BulkStatusResult
    {
        $results = [];
        $changed = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($messageIds as $messageId) {
            $result = $this->change($messageId, $to, $ctx);
            $results[] = $result;

            if ($result->outcome === StatusResult::CHANGED) {
                ++$changed;
            } elseif ($result->outcome === StatusResult::UNCHANGED) {
                ++$unchanged;
            } else {
                ++$failed;
            }
        }

        return new BulkStatusResult($results, $changed, $unchanged, $failed);
    }

    private function performChange(
        int $messageId,
        MessageStatus $to,
        StatusContext $ctx,
        string $source,
    ): StatusResult {
        $message = $this->queueRepository->findById($messageId);

        if ($message === null) {
            return new StatusResult($messageId, StatusResult::NOT_FOUND);
        }

        if ($message->status === $to) {
            return new StatusResult($messageId, StatusResult::UNCHANGED, $message->status, $to);
        }

        if ($ctx->expectedFrom !== null && $message->status !== $ctx->expectedFrom) {
            return new StatusResult(
                $messageId,
                StatusResult::STALE_STATE,
                $message->status,
                $to,
                'Message is no longer in expected status',
            );
        }

        try {
            $this->policy->assertTransition($message->status, $to, $source);
        } catch (\InvalidArgumentException $e) {
            return new StatusResult($messageId, StatusResult::INVALID_TRANSITION, $message->status, $to, $e->getMessage());
        }

        if ($this->authorizer !== null && $source === StatusTransitionPolicy::SOURCE_APPLICATION) {
            if (! $this->authorizer->authorize($ctx->actor, [$messageId])) {
                return new StatusResult($messageId, StatusResult::DENIED, $message->status, $to, 'Not authorised');
            }
        }

        if ($ctx->dryRun) {
            return new StatusResult($messageId, StatusResult::CHANGED, $message->status, $to);
        }

        $this->queueRepository->updateStatus($messageId, $to);
        $this->statusChangeRepository->record(
            $messageId,
            $message->campaignId,
            $message->status,
            $to,
            $ctx->actor,
            $ctx->reason,
            $source,
        );

        $this->adjustCampaignCounters($message->campaignId, $message->status, $to);

        return new StatusResult($messageId, StatusResult::CHANGED, $message->status, $to);
    }

    private function adjustCampaignCounters(?int $campaignId, MessageStatus $from, MessageStatus $to): void
    {
        if ($campaignId === null) {
            return;
        }

        $changes = [];
        if ($from === MessageStatus::QUEUED && $to !== MessageStatus::QUEUED) {
            $changes['queued_count'] = -1;
        } elseif ($from !== MessageStatus::QUEUED && $to === MessageStatus::QUEUED) {
            $changes['queued_count'] = 1;
        }

        if ($changes !== []) {
            $this->campaignRepository->incrementCounters($campaignId, $changes);
        }
    }
}
