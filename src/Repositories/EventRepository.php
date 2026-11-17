<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use ExcelleInsights\Email\Entities\EmailEvent;
use PDO;

class EventRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'events';
    }

    public function existsByDedupeKey(string $dedupeKey): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE dedupe_key = :dedupe_key LIMIT 1");
        $stmt->execute([':dedupe_key' => $dedupeKey]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(EmailEvent $event): EmailEvent
    {
        $dedupeKey = $this->dedupeKey($event);
        $eventData = $event->eventData === null ? null : json_encode($event->eventData);

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, provider, message_id, queue_id, campaign_id, recipient_email, event_type,
              dedupe_key, event_data, ip_address, user_agent, event_timestamp, created_at)
             VALUES
             (:tenant_id, :provider, :message_id, :queue_id, :campaign_id, :recipient_email, :event_type,
              :dedupe_key, :event_data, :ip_address, :user_agent, :event_timestamp, CURRENT_TIMESTAMP)",
        );
        $stmt->execute([
            ':tenant_id' => $event->tenantId,
            ':provider' => $event->provider ?? null,
            ':message_id' => $event->messageId,
            ':queue_id' => $event->queueId,
            ':campaign_id' => $event->campaignId,
            ':recipient_email' => strtolower(trim($event->recipientEmail)),
            ':event_type' => $event->eventType,
            ':dedupe_key' => $dedupeKey,
            ':event_data' => $eventData === false ? null : $eventData,
            ':ip_address' => $event->ipAddress,
            ':user_agent' => $event->userAgent,
            ':event_timestamp' => $this->formatDateTime($event->eventTimestamp),
        ]);

        $created = $this->findById((int) $this->pdo->lastInsertId());
        if ($created === null) {
            throw new \RuntimeException('Failed to create email event row');
        }

        return $created;
    }

    /**
     * @return array<string, int>
     */
    public function countByCampaign(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT event_type, COUNT(*) AS total FROM {$this->table}
             WHERE campaign_id = :campaign_id GROUP BY event_type",
        );
        $stmt->execute([':campaign_id' => $campaignId]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['event_type']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int, EmailEvent>
     */
    public function findByCampaign(int $campaignId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE campaign_id = :campaign_id
             ORDER BY event_timestamp DESC, id DESC LIMIT :limit",
        );
        $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): EmailEvent => $this->mapRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function findById(int $id): ?EmailEvent
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    private function dedupeKey(EmailEvent $event): string
    {
        if ($event->dedupeKey !== null && $event->dedupeKey !== '') {
            return $event->dedupeKey;
        }

        return hash('sha256', implode("\0", [
            $event->provider ?? '',
            $event->messageId ?? '',
            strtolower(trim($event->recipientEmail)),
            $event->eventType,
        ]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): EmailEvent
    {
        $eventData = json_decode((string) ($row['event_data'] ?? ''), true);

        return new EmailEvent(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            messageId: $this->firstString($row['message_id'] ?? null),
            queueId: $row['queue_id'] === null && $row['email_queue_id'] === null
                ? null
                : (int) ($row['queue_id'] ?? $row['email_queue_id']),
            campaignId: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            recipientEmail: (string) ($row['recipient_email'] ?? ''),
            eventType: (string) ($row['event_type'] ?? 'unknown'),
            eventData: is_array($eventData) ? $eventData : null,
            ipAddress: $this->firstString($row['ip_address'] ?? null),
            userAgent: $this->firstString($row['user_agent'] ?? null),
            eventTimestamp: $this->parseDateTime($row['event_timestamp'] ?? null),
            createdAt: $this->parseDateTime($row['created_at'] ?? null),
            dedupeKey: $this->firstString($row['dedupe_key'] ?? null),
            provider: $this->firstString($row['provider'] ?? null),
        );
    }

    private function firstString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
