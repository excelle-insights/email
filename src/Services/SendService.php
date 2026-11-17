<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use DateTimeImmutable;
use DateTimeZone;
use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Entities\BulkSendResult;
use ExcelleInsights\Email\Entities\Campaign;
use ExcelleInsights\Email\Entities\DeliveryStatus;
use ExcelleInsights\Email\Entities\EmailTemplate;
use ExcelleInsights\Email\Entities\Message;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;
use ExcelleInsights\Email\Providers\ProviderRegistry;
use ExcelleInsights\Email\Repositories\CampaignRepository;
use ExcelleInsights\Email\Repositories\QueueRepository;
use ExcelleInsights\Email\Repositories\SenderRepository;
use ExcelleInsights\Email\Repositories\StatusChangeRepository;
use ExcelleInsights\Email\Validation\EmailAddressValidator;
use ExcelleInsights\Email\Validation\MessageValidator;
use Psr\Log\LoggerInterface;

class SendService
{
    public function __construct(
        private readonly EmailConfig $config,
        private readonly ProviderResolver $providerResolver,
        private readonly SenderRepository $senderRepository,
        private readonly QueueRepository $queueRepository,
        private readonly CampaignRepository $campaignRepository,
        private readonly SuppressionService $suppressionService,
        private readonly TemplateService $templateService,
        private readonly TemplateRenderer $templateRenderer,
        private readonly EmailAddressValidator $emailValidator,
        private readonly MessageValidator $messageValidator,
        private readonly ProviderRegistry $providerRegistry,
        private readonly StatusChangeRepository $statusChangeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(string $to, string $subject, string $html, array $options = []): SendResult
    {
        $tenantId = $this->optionalInt($options['tenant_id'] ?? null);
        $providerKey = $this->providerResolver->resolve($this->optionalString($options['provider'] ?? null), $tenantId);
        $recipient = $this->emailValidator->validate($to);

        [$rendered, $template] = $this->render($subject, $html, $options, $tenantId);
        $this->messageValidator->validate($rendered);

        $idempotencyKey = $this->optionalString($options['idempotency_key'] ?? null);
        $existing = $this->findByIdempotencyKey($idempotencyKey, $tenantId);
        if ($existing !== null) {
            return new SendResult($existing->status, $existing->id, $existing->providerMessageId);
        }

        $category = $template?->category ?? (string) ($options['category'] ?? EmailTemplate::CATEGORY_MARKETING);
        $this->assertNotSuppressed($recipient, $tenantId, $category, $options);

        $campaignId = $this->optionalInt($options['campaign_id'] ?? null);
        $scheduledAt = $this->resolveScheduledAt($options);
        $status = $this->resolveInitialStatus($options, $scheduledAt);
        $identity = $this->senderIdentity($providerKey, $tenantId, $options);

        $message = $this->persistMessage(
            tenantId: $tenantId,
            campaignId: $campaignId,
            providerKey: $providerKey,
            to: $recipient,
            recipientName: $this->optionalString($options['recipient_name'] ?? null),
            fromEmail: $identity['from_email'],
            fromName: $identity['from_name'],
            replyTo: $identity['reply_to'],
            cc: $this->stringList($options['cc'] ?? []),
            bcc: $this->stringList($options['bcc'] ?? []),
            subject: $rendered->subject,
            html: $rendered->html,
            text: $rendered->text,
            templateKey: $this->optionalString($options['template'] ?? null),
            templateId: $template?->id,
            templateVersion: $template?->version,
            templateVariables: $this->variables($options['vars'] ?? $options['template_vars'] ?? []),
            attachments: $this->stringList($options['attachments'] ?? []),
            status: $status,
            scheduledAt: $scheduledAt,
            idempotencyKey: $idempotencyKey,
            origin: (string) ($options['origin'] ?? 'campaign'),
            priority: (int) ($options['priority'] ?? 5),
            metadata: $this->arrayOrNull($options['metadata'] ?? null),
        );

        if ($campaignId !== null && $status === MessageStatus::QUEUED) {
            $this->campaignRepository->incrementCounters($campaignId, ['queued_count' => 1]);
        }

        return new SendResult($message->status, $message->id);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendNow(string $to, string $subject, string $html, array $options = []): SendResult
    {
        $result = $this->send($to, $subject, $html, $options);

        if ($result->localId === null || $result->status === MessageStatus::SENT) {
            return $result;
        }

        $message = $this->queueRepository->findById($result->localId);
        if ($message === null) {
            return $result;
        }

        return $this->dispatch($message);
    }

    /**
     * @param array<int, array<string, mixed>> $recipients
     * @param array<string, mixed> $options
     */
    public function sendBulk(array $recipients, string $subject, string $html, array $options = []): BulkSendResult
    {
        $tenantId = $this->optionalInt($options['tenant_id'] ?? null);
        $providerKey = $this->providerResolver->resolve($this->optionalString($options['provider'] ?? null), $tenantId);
        $scheduledAt = $this->resolveScheduledAt($options);
        $status = $this->resolveInitialStatus($options, $scheduledAt);
        $identity = $this->senderIdentity($providerKey, $tenantId, $options);
        $templateKey = $this->optionalString($options['template'] ?? null);
        $templateId = null;

        if ($templateKey !== null) {
            $templateId = $this->templateService->get($templateKey, $tenantId)->id;
        }

        $existingCampaignId = $this->optionalInt($options['campaign_id'] ?? null);
        if ($existingCampaignId !== null) {
            $campaign = $this->campaignRepository->find($existingCampaignId);
            if ($campaign->status === Campaign::STATUS_CANCELLED) {
                throw new \RuntimeException("Email campaign {$existingCampaignId} is cancelled");
            }

            $campaignId = $existingCampaignId;
            $this->campaignRepository->incrementCounters($campaignId, [
                'total_recipients' => count($recipients),
            ]);
            if ($campaign->status === Campaign::STATUS_DRAFT && $status === MessageStatus::QUEUED) {
                $this->campaignRepository->setStatus($campaignId, Campaign::STATUS_QUEUED);
            }
        } else {
            $campaign = $this->campaignRepository->create(new Campaign(
                tenantId: $tenantId,
                name: (string) ($options['campaign_name'] ?? $options['name'] ?? 'Email campaign'),
                provider: $providerKey,
                templateId: $templateId,
                subject: $subject,
                totalRecipients: count($recipients),
                status: $status === MessageStatus::QUEUED ? Campaign::STATUS_QUEUED : Campaign::STATUS_DRAFT,
                sendDate: $scheduledAt,
                metadata: $this->arrayOrNull($options['metadata'] ?? null),
            ));

            $campaignId = $campaign->id;
            if ($campaignId === null) {
                throw new \RuntimeException('Failed to create email campaign');
            }
        }

        $queued = 0;
        $failed = 0;
        $failures = [];

        foreach ($recipients as $index => $recipient) {
            try {
                $to = $recipient['to'] ?? $recipient['email'] ?? null;
                if (! is_string($to)) {
                    throw new \InvalidArgumentException('Recipient missing email address');
                }

                $email = $this->emailValidator->validate($to);
                $variables = array_merge(
                    $this->variables($options['vars'] ?? $options['template_vars'] ?? []),
                    $this->variables($recipient['vars'] ?? $recipient['template_vars'] ?? []),
                );
                [$rendered, $template] = $this->render($subject, $html, [
                    'template' => $templateKey,
                    'vars' => $variables,
                ], $tenantId);
                $this->messageValidator->validate($rendered);

                $category = $template?->category ?? (string) ($options['category'] ?? EmailTemplate::CATEGORY_MARKETING);
                $this->assertNotSuppressed($email, $tenantId, $category, array_merge($options, $recipient));

                $idempotencyKey = $this->optionalString($recipient['idempotency_key'] ?? $options['idempotency_key'] ?? null);
                if ($this->findByIdempotencyKey($idempotencyKey, $tenantId) !== null) {
                    throw new \RuntimeException("Duplicate message for {$email}");
                }

                $this->persistMessage(
                    tenantId: $tenantId,
                    campaignId: $campaignId,
                    providerKey: $providerKey,
                    to: $email,
                    recipientName: $this->optionalString($recipient['recipient_name'] ?? null),
                    fromEmail: $identity['from_email'],
                    fromName: $identity['from_name'],
                    replyTo: $this->optionalString($recipient['reply_to'] ?? $identity['reply_to']),
                    cc: $this->stringList($recipient['cc'] ?? $options['cc'] ?? []),
                    bcc: $this->stringList($recipient['bcc'] ?? $options['bcc'] ?? []),
                    subject: $rendered->subject,
                    html: $rendered->html,
                    text: $rendered->text,
                    templateKey: $templateKey,
                    templateId: $template?->id,
                    templateVersion: $template?->version,
                    templateVariables: $variables,
                    attachments: $this->stringList($recipient['attachments'] ?? $options['attachments'] ?? []),
                    status: $status,
                    scheduledAt: $scheduledAt,
                    idempotencyKey: $idempotencyKey,
                    origin: (string) ($recipient['origin'] ?? $options['origin'] ?? 'campaign'),
                    priority: (int) ($recipient['priority'] ?? $options['priority'] ?? 5),
                    metadata: $this->arrayOrNull($recipient['metadata'] ?? $options['metadata'] ?? null),
                );

                ++$queued;
            } catch (\Throwable $e) {
                ++$failed;
                $failures[] = [
                    'index' => $index,
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->campaignRepository->incrementCounters($campaignId, [
            'queued_count' => $queued,
            'failed_count' => $failed,
        ]);

        return new BulkSendResult($campaignId, count($recipients), $queued, $failed, $failures);
    }

    public function dispatch(Message $message): SendResult
    {
        $messageId = $message->id;
        $providerKey = $message->providerKey;

        if ($messageId === null || $providerKey === null) {
            return new SendResult(MessageStatus::FAILED, $messageId, error: 'Message is missing id or provider');
        }

        if ($message->status === MessageStatus::SENT) {
            return new SendResult(MessageStatus::SENT, $messageId, $message->providerMessageId);
        }

        $originalStatus = $message->status;
        if ($originalStatus !== MessageStatus::PROCESSING) {
            $this->queueRepository->updateStatus($messageId, MessageStatus::PROCESSING);
            $this->recordChange($message, $originalStatus, MessageStatus::PROCESSING);
        }

        try {
            $provider = $this->providerRegistry->get($providerKey);
            $result = $provider->send($this->sendRequest($message));

            if (! $result->isSuccess()) {
                throw new \RuntimeException($result->error ?? 'Provider rejected the message');
            }

            $this->queueRepository->markSent($messageId, $result->providerMessageId ?? '');
            $this->recordChange($message, MessageStatus::PROCESSING, MessageStatus::SENT);
            $this->recordOutcome($message, $originalStatus, 'sent');

            return new SendResult(MessageStatus::SENT, $messageId, $result->providerMessageId);
        } catch (\Throwable $e) {
            $this->logger->error('Email dispatch failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $this->queueRepository->markFailed($messageId, $e->getMessage(), $message->attempts + 1);
            $this->recordChange($message, MessageStatus::PROCESSING, MessageStatus::FAILED);
            $this->recordOutcome($message, $originalStatus, 'failed');

            return new SendResult(MessageStatus::FAILED, $messageId, error: $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: \ExcelleInsights\Email\Entities\RenderedMessage, 1: EmailTemplate|null}
     */
    private function render(string $subject, string $html, array $options, ?int $tenantId): array
    {
        $variables = $this->variables($options['vars'] ?? $options['template_vars'] ?? []);
        $template = null;
        $templateKey = $this->optionalString($options['template'] ?? null);

        if ($templateKey !== null) {
            $preview = $this->templateService->preview($templateKey, $variables, $tenantId);
            $template = $this->templateService->get($templateKey, $tenantId);
            $rendered = new \ExcelleInsights\Email\Entities\RenderedMessage(
                subject: $preview->subject,
                html: $preview->html,
                text: $this->renderText($options, $variables),
                variables: $preview->variables,
            );

            return [$rendered, $template];
        }

        $rendered = new \ExcelleInsights\Email\Entities\RenderedMessage(
            subject: $this->templateRenderer->render($subject, $variables),
            html: $this->templateRenderer->render($html, $variables),
            text: $this->renderText($options, $variables),
            variables: $variables,
        );

        return [$rendered, null];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $variables
     */
    private function renderText(array $options, array $variables): ?string
    {
        $text = $options['text'] ?? null;

        return $text === null ? null : $this->templateRenderer->render((string) $text, $variables);
    }

    /**
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     * @param array<string, mixed>|null $templateVariables
     * @param array<int, string> $attachments
     * @param array<string, mixed>|null $metadata
     */
    private function persistMessage(
        ?int $tenantId,
        ?int $campaignId,
        string $providerKey,
        string $to,
        ?string $recipientName,
        ?string $fromEmail,
        ?string $fromName,
        ?string $replyTo,
        array $cc,
        array $bcc,
        string $subject,
        string $html,
        ?string $text,
        ?string $templateKey,
        ?int $templateId,
        ?int $templateVersion,
        ?array $templateVariables,
        array $attachments,
        MessageStatus $status,
        ?DateTimeImmutable $scheduledAt,
        ?string $idempotencyKey,
        string $origin,
        int $priority,
        ?array $metadata,
    ): Message {
        $message = $this->queueRepository->create(new Message(
            tenantId: $tenantId,
            campaignId: $campaignId,
            providerKey: $providerKey,
            to: $to,
            recipientName: $recipientName,
            fromEmail: $fromEmail,
            fromName: $fromName,
            replyTo: $replyTo,
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
            html: $html,
            text: $text,
            templateKey: $templateKey,
            templateId: $templateId,
            templateVersion: $templateVersion,
            templateVariables: $templateVariables,
            attachments: $attachments,
            status: $status,
            deliveryStatus: DeliveryStatus::NONE,
            scheduledAt: $scheduledAt,
            idempotencyKey: $idempotencyKey,
            origin: $origin,
            priority: $priority,
            metadata: $metadata,
        ));

        if ($status === MessageStatus::QUEUED) {
            $this->statusChangeRepository->record(
                $message->id,
                $campaignId,
                null,
                MessageStatus::QUEUED,
                'application',
                'queued by send service',
                StatusTransitionPolicy::SOURCE_APPLICATION,
            );
        }

        return $message;
    }

    private function sendRequest(Message $message): SendRequest
    {
        return new SendRequest(
            to: $message->to,
            subject: $message->subject,
            html: $message->html,
            text: $message->text,
            recipientName: $message->recipientName,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            replyTo: $message->replyTo,
            cc: $message->cc,
            bcc: $message->bcc,
            attachments: $message->attachments,
            templateKey: $message->templateKey,
            templateVars: $message->templateVariables ?? [],
            tenantId: $message->tenantId,
            providerKey: $message->providerKey,
            campaignId: $message->campaignId,
            idempotencyKey: $message->idempotencyKey,
            scheduledAt: $message->scheduledAt,
            metadata: $message->metadata ?? [],
            priority: $message->priority,
            origin: $message->origin,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{from_email: ?string, from_name: ?string, reply_to: ?string}
     */
    private function senderIdentity(string $providerKey, ?int $tenantId, array $options): array
    {
        $sender = $this->senderRepository->getSender($providerKey, $tenantId);

        return [
            'from_email' => $this->optionalString($options['from_email'] ?? null)
                ?? $this->optionalString($sender['from_email'] ?? null),
            'from_name' => $this->optionalString($options['from_name'] ?? null)
                ?? $this->optionalString($sender['from_name'] ?? null),
            'reply_to' => $this->optionalString($options['reply_to'] ?? null)
                ?? $this->optionalString($sender['reply_to'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertNotSuppressed(string $email, ?int $tenantId, string $category, array $options): void
    {
        $bypass = (bool) ($options['bypass_suppression'] ?? false);
        if ($this->suppressionService->isBlocked($email, $tenantId, $category, $bypass)) {
            throw new \RuntimeException("Recipient {$email} is suppressed");
        }
    }

    private function findByIdempotencyKey(?string $key, ?int $tenantId): ?Message
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->queueRepository->findByIdempotencyKey($key, $tenantId, $this->config->idempotencyKeyTtlHours());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveScheduledAt(array $options): ?DateTimeImmutable
    {
        $scheduled = $options['scheduled_at'] ?? null;
        if ($scheduled === null) {
            return null;
        }

        $date = $scheduled instanceof \DateTimeInterface
            ? DateTimeImmutable::createFromInterface($scheduled)
            : new DateTimeImmutable((string) $scheduled);

        $timezone = $options['timezone'] ?? null;
        if ($timezone !== null) {
            $date = $date->setTimezone(new DateTimeZone((string) $timezone));
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveInitialStatus(array $options, ?DateTimeImmutable $scheduledAt): MessageStatus
    {
        if ((bool) ($options['pending'] ?? false)) {
            return MessageStatus::PENDING;
        }

        return $scheduledAt !== null ? MessageStatus::SCHEDULED : MessageStatus::QUEUED;
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

    private function recordOutcome(Message $message, MessageStatus $originalStatus, string $counter): void
    {
        $campaignId = $message->campaignId;
        if ($campaignId === null) {
            return;
        }

        $changes = [];
        if ($originalStatus === MessageStatus::QUEUED) {
            $changes['queued_count'] = -1;
        }
        $changes[$counter === 'sent' ? 'sent_count' : 'failed_count'] = 1;

        $this->campaignRepository->incrementCounters($campaignId, $changes);
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private function variables(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    private function optionalInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
