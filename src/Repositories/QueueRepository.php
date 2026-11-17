<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use ExcelleInsights\Email\Entities\DeliveryStatus;
use ExcelleInsights\Email\Entities\Message;
use ExcelleInsights\Email\Entities\MessageStatus;
use PDO;

class QueueRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'queue';
    }

    public function create(Message $message): Message
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, campaign_id, provider, provider_message_id, recipient_email, recipient_name,
              from_email, from_name, reply_to, cc, bcc, subject, content_sections, text_content,
              template, template_id, template_version, template_variables, attachments, status,
              delivery_status, send_date, attempts, idempotency_key, error_message, origin, priority,
              metadata, created_at, updated_at)
             VALUES
             (:tenant_id, :campaign_id, :provider, :provider_message_id, :recipient_email, :recipient_name,
              :from_email, :from_name, :reply_to, :cc, :bcc, :subject, :content_sections, :text_content,
              :template, :template_id, :template_version, :template_variables, :attachments, :status,
              :delivery_status, :send_date, :attempts, :idempotency_key, :error_message, :origin, :priority,
              :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $message->tenantId,
            ':campaign_id' => $message->campaignId,
            ':provider' => $message->providerKey,
            ':provider_message_id' => $message->providerMessageId,
            ':recipient_email' => $message->to,
            ':recipient_name' => $message->recipientName,
            ':from_email' => $message->fromEmail,
            ':from_name' => $message->fromName,
            ':reply_to' => $message->replyTo,
            ':cc' => $this->encodeJson($message->cc),
            ':bcc' => $this->encodeJson($message->bcc),
            ':subject' => $message->subject,
            ':content_sections' => $message->html,
            ':text_content' => $message->text,
            ':template' => $message->templateKey,
            ':template_id' => $message->templateId,
            ':template_version' => $message->templateVersion,
            ':template_variables' => $this->encodeJson($message->templateVariables),
            ':attachments' => $this->encodeJson($message->attachments),
            ':status' => $message->status->value,
            ':delivery_status' => $message->deliveryStatus->value,
            ':send_date' => $this->formatDateTime($message->scheduledAt),
            ':attempts' => $message->attempts,
            ':idempotency_key' => $message->idempotencyKey,
            ':error_message' => $message->error,
            ':origin' => $message->origin,
            ':priority' => $message->priority,
            ':metadata' => $this->encodeJson($message->metadata),
        ]);

        $created = $this->findById((int) $this->pdo->lastInsertId());
        if ($created === null) {
            throw new \RuntimeException('Failed to create email queue row');
        }

        return $created;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findById(int $id): ?Message
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByProviderMessageId(string $providerMessageId, string $provider): ?Message
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE provider_message_id = :provider_message_id AND provider = :provider LIMIT 1",
        );
        $stmt->execute([':provider_message_id' => $providerMessageId, ':provider' => $provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByIdempotencyKey(string $key, ?int $tenantId, int $ttlHours): ?Message
    {
        if ($tenantId === null) {
            $where = 'tenant_id IS NULL';
            $params = [];
        } else {
            $where = 'tenant_id = :tenant_id';
            $params = [':tenant_id' => $tenantId];
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} AND idempotency_key = :key
             ORDER BY created_at DESC LIMIT 1",
        );
        $stmt->execute(array_merge($params, [':key' => $key]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $createdAt = $this->parseDateTime($row['created_at'] ?? null);
        if ($createdAt === null || $createdAt < (new \DateTimeImmutable())->modify("-{$ttlHours} hours")) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<int, Message>
     */
    public function findDueScheduled(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = :status AND send_date <= CURRENT_TIMESTAMP
             ORDER BY send_date ASC LIMIT :limit",
        );
        $stmt->bindValue(':status', MessageStatus::SCHEDULED->value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, Message>
     */
    public function findQueued(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE status = :status
             ORDER BY priority ASC, created_at ASC LIMIT :limit",
        );
        $stmt->bindValue(':status', MessageStatus::QUEUED->value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, Message>
     */
    public function findFailedRetryable(int $maxAttempts, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE status = :status AND attempts < :max_attempts
             ORDER BY priority ASC, updated_at ASC LIMIT :limit",
        );
        $stmt->bindValue(':status', MessageStatus::FAILED->value);
        $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, int>
     */
    public function findIdsByCampaignId(int $campaignId): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE campaign_id = :campaign_id");
        $stmt->execute([':campaign_id' => $campaignId]);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return array<int, Message>
     */
    public function findByCampaign(int $campaignId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE campaign_id = :campaign_id
             ORDER BY id DESC LIMIT :limit",
        );
        $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): Message => $this->mapRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return array<string, int>
     */
    public function countByCampaign(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS total FROM {$this->table}
             WHERE campaign_id = :campaign_id GROUP BY status",
        );
        $stmt->execute([':campaign_id' => $campaignId]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function findLatestByRecipient(string $recipient, string $provider, ?int $tenantId): ?Message
    {
        $where = 'recipient_email = :recipient AND provider = :provider';
        $params = [
            ':recipient' => strtolower(trim($recipient)),
            ':provider' => $provider,
        ];

        if ($tenantId === null) {
            $where .= ' AND tenant_id IS NULL';
        } else {
            $where .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 1",
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function updateDeliveryStatusIfMoreAdvanced(int $id, string $deliveryStatus): bool
    {
        $rank = $this->deliveryRank($deliveryStatus);
        if ($rank === null) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT delivery_status FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();

        $currentRank = $this->deliveryRank(is_string($current) ? $current : '');
        if ($currentRank !== null && $rank <= $currentRank) {
            return false;
        }

        return $this->updateDeliveryStatus($id, $deliveryStatus) === 1;
    }

    public function attachProviderMessageIdIfMissing(int $id, string $providerMessageId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET provider_message_id = COALESCE(provider_message_id, :provider_message_id),
                 message_id = COALESCE(message_id, :provider_message_id),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );
        $stmt->execute([
            ':provider_message_id' => $providerMessageId,
            ':id' => $id,
        ]);
    }

    public function claimForProcessing(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :processing, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :queued",
        );
        $stmt->execute([
            ':processing' => MessageStatus::PROCESSING->value,
            ':id' => $id,
            ':queued' => MessageStatus::QUEUED->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markSent(int $id, string $providerMessageId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = :sent, provider_message_id = :provider_message_id, error_message = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
        );
        $stmt->execute([
            ':sent' => MessageStatus::SENT->value,
            ':provider_message_id' => $providerMessageId,
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error, int $attempts): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :failed, error_message = :error, attempts = :attempts,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([
            ':failed' => MessageStatus::FAILED->value,
            ':error' => $error,
            ':attempts' => $attempts,
            ':id' => $id,
        ]);
    }

    public function markRetryQueued(int $id, int $attempts): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :queued, attempts = :attempts,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([
            ':queued' => MessageStatus::QUEUED->value,
            ':attempts' => $attempts,
            ':id' => $id,
        ]);
    }

    public function updateStatus(int $id, MessageStatus $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([':status' => $status->value, ':id' => $id]);
    }

    public function updateDeliveryStatus(int $id, string $deliveryStatus): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET delivery_status = :delivery_status,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([':delivery_status' => $deliveryStatus, ':id' => $id]);

        return $stmt->rowCount();
    }

    private function deliveryRank(string $deliveryStatus): ?int
    {
        return match ($deliveryStatus) {
            DeliveryStatus::NONE->value, DeliveryStatus::UNKNOWN->value => 0,
            DeliveryStatus::DEFERRED->value => 1,
            DeliveryStatus::DELIVERED->value => 2,
            DeliveryStatus::COMPLAINED->value => 3,
            DeliveryStatus::BOUNCED->value => 4,
            DeliveryStatus::SUPPRESSED->value => 5,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Message
    {
        $templateVariables = $this->stringKeyedArray($this->decodeJson($row['template_variables'] ?? null))
            ?? $this->stringKeyedArray($this->decodeJson($row['variables'] ?? null));

        return new Message(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            providerKey: $row['provider'] === null ? null : (string) $row['provider'],
            providerMessageId: $this->firstString($row['provider_message_id'] ?? null, $row['message_id'] ?? null),
            to: (string) $row['recipient_email'],
            recipientName: $this->firstString($row['recipient_name'] ?? null),
            fromEmail: $this->firstString($row['from_email'] ?? null),
            fromName: $this->firstString($row['from_name'] ?? null),
            replyTo: $this->firstString($row['reply_to'] ?? null),
            cc: $this->stringList($this->decodeJson($row['cc'] ?? null)),
            bcc: $this->stringList($this->decodeJson($row['bcc'] ?? null)),
            subject: (string) ($row['subject'] ?? ''),
            html: (string) ($row['content_sections'] ?? ''),
            text: $this->firstString($row['text_content'] ?? null),
            templateKey: $this->firstString($row['template'] ?? null),
            templateId: $row['template_id'] === null ? null : (int) $row['template_id'],
            templateVersion: $row['template_version'] === null ? null : (int) $row['template_version'],
            templateVariables: $templateVariables,
            attachments: $this->stringList($this->decodeJson($row['attachments'] ?? null)),
            status: MessageStatus::from((string) ($row['status'] ?? MessageStatus::QUEUED->value)),
            deliveryStatus: DeliveryStatus::from((string) ($row['delivery_status'] ?? DeliveryStatus::NONE->value)),
            scheduledAt: $this->parseDateTime($row['send_date'] ?? null),
            attempts: (int) ($row['attempts'] ?? 0),
            idempotencyKey: $this->firstString($row['idempotency_key'] ?? null),
            error: $this->firstString($row['error_message'] ?? null, $row['error'] ?? null),
            origin: (string) ($row['origin'] ?? 'campaign'),
            priority: (int) ($row['priority'] ?? 5),
            metadata: $this->stringKeyedArray($this->decodeJson($row['metadata'] ?? null)),
            createdAt: $this->parseDateTime($row['created_at'] ?? null),
            updatedAt: $this->parseDateTime($row['updated_at'] ?? null),
        );
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed>|null $value
     */
    private function encodeJson(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param array<array-key, mixed>|null $value
     *
     * @return array<int, string>
     */
    private function stringList(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    /**
     * @param array<array-key, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    private function stringKeyedArray(?array $value): ?array
    {
        if ($value === null) {
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

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function formatDateTime(?\DateTimeInterface $dateTime): ?string
    {
        return $dateTime === null ? null : $dateTime->format('Y-m-d H:i:s');
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
