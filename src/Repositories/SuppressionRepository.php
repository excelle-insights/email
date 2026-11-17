<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use PDO;

class SuppressionRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'suppressions';
    }

    public function exists(string $email, ?int $tenantId, ?string $type = null): bool
    {
        [$where, $params] = $this->tenantWhere($tenantId);
        $params[':email'] = strtolower(trim($email));

        if ($type !== null) {
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE {$where} AND email = :email LIMIT 1");
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public function add(string $email, ?int $tenantId, string $type, ?string $source = null): void
    {
        if ($this->exists($email, $tenantId, $type)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (tenant_id, email, type, source, created_at)
             VALUES (:tenant_id, :email, :type, :source, CURRENT_TIMESTAMP)",
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':email' => strtolower(trim($email)),
            ':type' => $type,
            ':source' => $source,
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tenantWhere(?int $tenantId): array
    {
        if ($tenantId === null) {
            return ['tenant_id IS NULL', []];
        }

        return ['(tenant_id = :tenant_id OR tenant_id IS NULL)', [':tenant_id' => $tenantId]];
    }
}
