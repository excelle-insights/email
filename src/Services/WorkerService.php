<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use DateTimeImmutable;
use ExcelleInsights\Email\Entities\Message;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use ExcelleInsights\Email\Repositories\StatusChangeRepository;
use Psr\Log\LoggerInterface;

class WorkerService
{
    public function __construct(
        private readonly QueueRepository $queueRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly StatusChangeRepository $statusChangeRepository,
        private readonly SendService $sendService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processBatch(int $batchSize): int
    {
        $this->releaseDueScheduledMessages($batchSize);
        $this->retryFailedMessages($batchSize);

        return $this->dispatchQueuedMessages($batchSize);
    }

    public function dispatchQueuedMessages(int $limit): int
    {
        $dispatched = 0;

        foreach ($this->queueRepository->findQueued($limit) as $message) {
            $messageId = $message->id;
            if ($messageId === null || ! $this->queueRepository->claimForProcessing($messageId)) {
                continue;
            }

            $claimed = $this->queueRepository->findById($messageId);
            if ($claimed === null) {
                continue;
            }

            $this->recordChange($claimed, MessageStatus::QUEUED, MessageStatus::PROCESSING);
            $this->sendService->dispatch($claimed);
            ++$dispatched;
        }

        return $dispatched;
    }

    public function releaseDueScheduledMessages(int $limit): int
    {
        $released = 0;

        foreach ($this->queueRepository->findDueScheduled($limit) as $message) {
            $messageId = $message->id;
            if ($messageId === null) {
                continue;
            }

            $this->queueRepository->updateStatus($messageId, MessageStatus::QUEUED);
            $this->recordChange($message, MessageStatus::SCHEDULED, MessageStatus::QUEUED);

            if ($message->campaignId !== null) {
                $this->campaignRepository->incrementCounters($message->campaignId, ['queued_count' => 1]);
            }

            ++$released;
        }

        return $released;
    }

    /**
     * @param array<int, int> $retryDelays
     */
    public function retryFailedMessages(int $limit, ?array $retryDelays = null, ?int $maxAttempts = null): int
    {
        $retryDelays ??= [1, 2, 4, 8, 16];
        $maxAttempts ??= count($retryDelays);
        $now = new DateTimeImmutable();
        $retried = 0;

        foreach ($this->queueRepository->findFailedRetryable($maxAttempts, $limit) as $message) {
            $messageId = $message->id;
            $updatedAt = $message->updatedAt;

            if ($messageId === null || $updatedAt === null) {
                continue;
            }

            $delayMinutes = $retryDelays[$message->attempts] ?? $retryDelays[array_key_last($retryDelays)];
            $nextRetry = DateTimeImmutable::createFromInterface($updatedAt)->modify("+{$delayMinutes} minutes");

            if ($nextRetry === false || $nextRetry > $now) {
                continue;
            }

            $this->queueRepository->markRetryQueued($messageId, $message->attempts + 1);
            $this->recordChange($message, MessageStatus::FAILED, MessageStatus::QUEUED);

            if ($message->campaignId !== null) {
                $this->campaignRepository->incrementCounters($message->campaignId, [
                    'queued_count' => 1,
                    'failed_count' => -1,
                ]);
            }

            ++$retried;
        }

        $this->logger->debug('Email retry pass completed', ['retried' => $retried]);

        return $retried;
    }

    private function recordChange(Message $message, MessageStatus $from, MessageStatus $to): void
    {
        $this->statusChangeRepository->record(
            $message->id,
            $message->campaignId,
            $from,
            $to,
            'worker',
            null,
            StatusTransitionPolicy::SOURCE_WORKER,
        );
    }
}
