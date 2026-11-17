<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use ExcelleInsights\Email\Entities\Campaign;
use PDO;

class CampaignRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'campaigns';
    }

    public function create(Campaign $campaign): Campaign
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, name, provider, template_id, subject, total_recipients, status, send_date, metadata,
              created_at, updated_at)
             VALUES (:tenant_id, :name, :provider, :template_id, :subject, :total_recipients, :status, :send_date,
                     :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );
        $stmt->execute([
            ':tenant_id' => $campaign->tenantId,
            ':name' => $campaign->name,
            ':provider' => $campaign->provider,
            ':template_id' => $campaign->templateId,
            ':subject' => $campaign->subject,
            ':total_recipients' => $campaign->totalRecipients,
            ':status' => $campaign->status,
            ':send_date' => $this->formatDateTime($campaign->sendDate),
            ':metadata' => $campaign->metadata === null ? null : json_encode($campaign->metadata),
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function find(int $id): Campaign
    {
        $campaign = $this->findOrNull($id);
        if ($campaign === null) {
            throw new \RuntimeException("Email campaign {$id} not found");
        }

        return $campaign;
    }

    public function findOrNull(int $id): ?Campaign
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * @param array<string, int> $changes
     */
    public function incrementCounters(int $id, array $changes): void
    {
        $parts = [];
        $params = [':id' => $id];

        foreach ($changes as $column => $delta) {
            $parts[] = "{$column} = {$column} + :{$column}";
            $params[":{$column}"] = $delta;
        }

        if ($parts === []) {
            return;
        }

        $parts[] = 'updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET " . implode(', ', $parts) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * @return array<int, int>
     */
    public function findMessageIds(int $campaignId): array
    {
        $table = $this->tablePrefix . 'queue';
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE campaign_id = :campaign_id");
        $stmt->execute([':campaign_id' => $campaignId]);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): Campaign
    {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);

        return new Campaign(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            name: (string) $row['name'],
            provider: $row['provider'] === null ? null : (string) $row['provider'],
            templateId: $row['template_id'] === null ? null : (int) $row['template_id'],
            subject: $row['subject'] === null ? null : (string) $row['subject'],
            totalRecipients: (int) ($row['total_recipients'] ?? 0),
            queuedCount: (int) ($row['queued_count'] ?? 0),
            sentCount: (int) ($row['sent_count'] ?? 0),
            deliveredCount: (int) ($row['delivered_count'] ?? 0),
            failedCount: (int) ($row['failed_count'] ?? 0),
            openedCount: (int) ($row['opened_count'] ?? 0),
            clickedCount: (int) ($row['clicked_count'] ?? 0),
            bouncedCount: (int) ($row['bounced_count'] ?? 0),
            status: (string) ($row['status'] ?? Campaign::STATUS_DRAFT),
            sendDate: $this->parseDateTime($row['send_date'] ?? null),
            metadata: is_array($metadata) ? $metadata : null,
            createdAt: $this->parseDateTime($row['created_at'] ?? null),
            updatedAt: $this->parseDateTime($row['updated_at'] ?? null),
        );
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
