<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Contracts\BulkStatusResult;
use ExcelleInsights\Email\Entities\BulkSendResult;
use ExcelleInsights\Email\Entities\Campaign;
use ExcelleInsights\Email\Entities\CampaignAnalytics;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\StatusContext;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\EventRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use InvalidArgumentException;

final class CampaignService
{
    public function __construct(
        private readonly CampaignRepository $campaignRepository,
        private readonly QueueRepository $queueRepository,
        private readonly EventRepository $eventRepository,
        private readonly SendService $sendService,
        private readonly MessageStatusService $messageStatusService,
    ) {
    }

    public function create(Campaign $campaign): Campaign
    {
        return $this->campaignRepository->create($campaign);
    }

    public function find(int $id): Campaign
    {
        return $this->campaignRepository->find($id);
    }

    /**
     * Queue host-supplied recipients. Pass a campaign id to reuse an existing
     * campaign, or recipients as the first argument to create one from options.
     *
     * @param int|array<int, array<string, mixed>> $campaign
     * @param array<int, array<string, mixed>> $recipients
     * @param array<string, mixed> $options
     */
    public function queue(int|array $campaign, array $recipients = [], array $options = []): BulkSendResult
    {
        if (is_array($campaign)) {
            return $this->sendService->sendBulk(
                $campaign,
                (string) ($options['subject'] ?? ''),
                (string) ($options['html'] ?? ''),
                $options,
            );
        }

        $existing = $this->campaignRepository->find($campaign);
        if ($existing->status === Campaign::STATUS_CANCELLED) {
            throw new InvalidArgumentException("Email campaign {$campaign} is cancelled");
        }

        if (! array_key_exists('tenant_id', $options) && $existing->tenantId !== null) {
            $options['tenant_id'] = $existing->tenantId;
        }
        if (($options['provider'] ?? null) === null && $existing->provider !== null) {
            $options['provider'] = $existing->provider;
        }
        $options['campaign_id'] = $campaign;

        $subject = (string) ($options['subject'] ?? $existing->subject ?? '');
        if ($subject === '' && ! isset($options['template'])) {
            throw new InvalidArgumentException('A subject or template is required to queue a campaign');
        }

        return $this->sendService->sendBulk(
            $recipients,
            $subject,
            (string) ($options['html'] ?? ''),
            $options,
        );
    }

    public function cancel(int $campaignId, string $actor = 'campaign-service'): BulkStatusResult
    {
        $this->campaignRepository->find($campaignId);

        return $this->messageStatusService->changeCampaign(
            $campaignId,
            MessageStatus::CANCELLED,
            new StatusContext(
                actor: $actor,
                reason: 'campaign cancelled',
                atomic: true,
            ),
        );
    }

    public function analytics(int $campaignId): CampaignAnalytics
    {
        $campaign = $this->campaignRepository->find($campaignId);
        $events = $this->eventRepository->countByCampaign($campaignId);
        $messages = $this->queueRepository->countByCampaign($campaignId);

        $engagementTotal = $campaign->deliveredCount > 0
            ? $campaign->deliveredCount
            : $campaign->totalRecipients;

        return new CampaignAnalytics(
            campaignId: $campaignId,
            totalRecipients: $campaign->totalRecipients,
            queuedCount: $campaign->queuedCount,
            sentCount: $campaign->sentCount,
            deliveredCount: $campaign->deliveredCount,
            failedCount: $campaign->failedCount,
            openedCount: $campaign->openedCount,
            clickedCount: $campaign->clickedCount,
            bouncedCount: $campaign->bouncedCount,
            eventsByType: $events,
            messagesByStatus: $messages,
            deliveryRate: $this->rate($campaign->deliveredCount, $campaign->totalRecipients),
            openRate: $this->rate($campaign->openedCount, $engagementTotal),
            clickRate: $this->rate($campaign->clickedCount, $engagementTotal),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function details(int $campaignId, int $limit = 50): array
    {
        return [
            'campaign' => $this->campaignRepository->find($campaignId),
            'analytics' => $this->analytics($campaignId),
            'messages' => $this->queueRepository->findByCampaign($campaignId, $limit),
            'events' => $this->eventRepository->findByCampaign($campaignId, $limit),
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator * 100) / $denominator, 2);
    }
}
