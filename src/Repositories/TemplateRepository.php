<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use ExcelleInsights\Email\Entities\EmailTemplate;
use PDO;

class TemplateRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'templates';
    }

    public function create(EmailTemplate $template): EmailTemplate
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, `key`, name, subject, category, status, current_version, created_at, updated_at)
             VALUES (:tenant_id, :key, :name, :subject, :category, :status, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );
        $stmt->execute([
            ':tenant_id' => $template->tenantId,
            ':key' => $template->key,
            ':name' => $template->name,
            ':subject' => $template->subject,
            ':category' => $template->category,
            ':status' => $template->status,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): EmailTemplate
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Email template {$id} not found");
        }

        return $this->mapRow($row);
    }

    public function findByKey(string $key, ?int $tenantId): ?EmailTemplate
    {
        if ($tenantId === null) {
            $where = 'tenant_id IS NULL';
            $params = [':key' => $key];
        } else {
            $where = '(tenant_id = :tenant_id OR tenant_id IS NULL)';
            $params = [':key' => $key, ':tenant_id' => $tenantId];
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE `key` = :key AND {$where} ORDER BY tenant_id DESC LIMIT 1",
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    /**
     * @return array<int, EmailTemplate>
     */
    public function list(?int $tenantId, ?string $status = null): array
    {
        if ($tenantId === null) {
            $where = 'tenant_id IS NULL';
            $params = [];
        } else {
            $where = '(tenant_id = :tenant_id OR tenant_id IS NULL)';
            $params = [':tenant_id' => $tenantId];
        }

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY name ASC");
        $stmt->execute($params);

        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function bumpVersion(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET current_version = current_version + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $stmt->execute([':id' => $id]);

        return $this->findById($id)->version;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(int $id, array $changes): void
    {
        $columns = ['key', 'name', 'subject', 'category', 'status'];
        $parts = [];
        $params = [':id' => $id];

        foreach ($changes as $column => $value) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $parts[] = $column === 'key' ? '`key` = :key' : "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        if ($parts === []) {
            return;
        }

        $parts[] = 'updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET " . implode(', ', $parts) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): EmailTemplate
    {
        return new EmailTemplate(
            id: (int) $row['id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            key: (string) $row['key'],
            name: (string) $row['name'],
            subject: (string) ($row['subject'] ?? ''),
            category: (string) ($row['category'] ?? EmailTemplate::CATEGORY_MARKETING),
            status: (string) ($row['status'] ?? EmailTemplate::STATUS_ACTIVE),
            version: (int) ($row['current_version'] ?? 1),
        );
    }
}
