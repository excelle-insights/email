<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Entities\DeliveryStatus;
use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\Message;
use ExcelleInsights\Email\Entities\WebhookResult;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\EventRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use ExcelleInsights\Email\Support\EventTypeNormalizer;

final class EventService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly QueueRepository $queueRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly SuppressionService $suppressionService,
        private readonly EventTypeNormalizer $eventTypes,
    ) {
    }

    public function process(EmailEvent $event, string $provider, string $payload): WebhookResult
    {
        $eventType = $this->eventTypes->normalize($event->eventType);
        $dedupeKey = $this->dedupeKey($provider, $event, $eventType, $payload);

        return $this->queueRepository->transaction(
            function () use ($event, $provider, $eventType, $dedupeKey): WebhookResult {
                if ($this->eventRepository->existsByDedupeKey($dedupeKey)) {
                    return new WebhookResult(
                        WebhookResult::DUPLICATE,
                        eventType: $eventType,
                    );
                }

                $message = $this->resolveMessage($event, $provider);
                $stored = $this->eventRepository->create(new EmailEvent(
                    tenantId: $message?->tenantId ?? $event->tenantId,
                    provider: $provider,
                    messageId: $event->messageId ?? $message?->providerMessageId,
                    queueId: $message?->id ?? $event->queueId,
                    campaignId: $message?->campaignId ?? $event->campaignId,
                    recipientEmail: $event->recipientEmail,
                    eventType: $eventType,
                    eventData: $event->eventData,
                    ipAddress: $event->ipAddress,
                    userAgent: $event->userAgent,
                    eventTimestamp: $event->eventTimestamp,
                    dedupeKey: $dedupeKey,
                ));
                $campaignId = $message?->campaignId ?? $event->campaignId;

                if ($message?->id !== null) {
                    if ($event->messageId !== null && $event->messageId !== '') {
                        $this->queueRepository->attachProviderMessageIdIfMissing($message->id, $event->messageId);
                    }

                    $deliveryStatus = $this->deliveryStatus($eventType);
                    if ($deliveryStatus !== null) {
                        $this->queueRepository->updateDeliveryStatusIfMoreAdvanced(
                            $message->id,
                            $deliveryStatus->value,
                        );
                    }
                }

                if ($event->recipientEmail !== '') {
                    $this->suppressionService->applyEvent(
                        $event->recipientEmail,
                        $message?->tenantId ?? $event->tenantId,
                        $eventType,
                        'webhook:' . $provider . ':' . $eventType,
                    );
                }

                $this->updateCampaignCounters($campaignId, $eventType);

                return new WebhookResult(
                    WebhookResult::RECEIVED,
                    eventId: $stored->id,
                    messageId: $message?->id,
                    queueId: $message?->id,
                    campaignId: $campaignId,
                    eventType: $eventType,
                );
            },
        );
    }

    private function resolveMessage(EmailEvent $event, string $provider): ?Message
    {
        if ($event->messageId !== null && $event->messageId !== '') {
            $message = $this->queueRepository->findByProviderMessageId($event->messageId, $provider);
            if ($message !== null) {
                return $message;
            }
        }

        if ($event->recipientEmail === '') {
            return null;
        }

        return $this->queueRepository->findLatestByRecipient(
            $event->recipientEmail,
            $provider,
            $event->tenantId,
        );
    }

    private function deliveryStatus(string $eventType): ?DeliveryStatus
    {
        return match ($eventType) {
            'delivered' => DeliveryStatus::DELIVERED,
            'deferred' => DeliveryStatus::DEFERRED,
            'bounced' => DeliveryStatus::BOUNCED,
            'complained' => DeliveryStatus::COMPLAINED,
            'unsubscribed' => DeliveryStatus::SUPPRESSED,
            default => null,
        };
    }

    private function updateCampaignCounters(?int $campaignId, string $eventType): void
    {
        if ($campaignId === null || $this->campaignRepository->findOrNull($campaignId) === null) {
            return;
        }

        $counter = match ($eventType) {
            'delivered' => 'delivered_count',
            'bounced', 'complained' => 'bounced_count',
            'opened' => 'opened_count',
            'clicked' => 'clicked_count',
            default => null,
        };

        if ($counter === null) {
            return;
        }

        $this->campaignRepository->incrementCounters($campaignId, [$counter => 1]);
    }

    private function dedupeKey(string $provider, EmailEvent $event, string $eventType, string $payload): string
    {
        $recipient = strtolower(trim($event->recipientEmail));
        $messageId = $event->messageId ?? '';

        return hash('sha256', implode("\0", [
            $provider,
            $messageId,
            $eventType,
            $recipient,
            $messageId === '' || $recipient === '' ? hash('sha256', $payload) : '',
        ]));
    }
}
