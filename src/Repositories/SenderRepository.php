<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Repositories;

use ExcelleInsights\Email\Support\CryptoInterface;
use PDO;

class SenderRepository
{
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CryptoInterface $crypto,
        string $tablePrefix,
    ) {
        $this->table = $tablePrefix . 'senders';
    }

    /**
     * Get the default provider key for a tenant or globally.
     */
    public function getDefaultSender(?int $tenantId): ?string
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
        $stmt = $this->pdo->prepare(
            "SELECT provider_key FROM {$this->table} WHERE {$where} AND enabled = TRUE AND is_default = TRUE LIMIT 1",
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (string) $row['provider_key'];
    }

    public function setDefaultSender(string $providerKey, ?int $tenantId): void
    {
        $this->pdo->beginTransaction();

        try {
            [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
            $clear = $this->pdo->prepare("UPDATE {$this->table} SET is_default = FALSE WHERE {$where}");
            $clear->execute($params);

            $existing = $this->findSender($providerKey, $tenantId);
            if ($existing !== null) {
                $stmt = $this->pdo->prepare(
                    "UPDATE {$this->table} SET is_default = TRUE, enabled = TRUE, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                );
                $stmt->execute([':id' => $existing['id']]);
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (tenant_id, provider_key, enabled, is_default, created_at, updated_at)
                     VALUES (:tenant_id, :provider_key, TRUE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                );
                $stmt->execute([':tenant_id' => $tenantId, ':provider_key' => $providerKey]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get a sender with decrypted credentials. A tenant sender wins over the global one.
     *
     * @return array<string, mixed>|null
     */
    public function getSender(string $providerKey, ?int $tenantId): ?array
    {
        $row = $this->findSender($providerKey, $tenantId) ?? $this->findSender($providerKey, null);

        if ($row === null) {
            return null;
        }

        $row['credentials'] = $this->decryptCredentials($row['credentials'] ?? null);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCredentials(string $providerKey, ?int $tenantId): ?array
    {
        $sender = $this->getSender($providerKey, $tenantId);
        $credentials = $sender['credentials'] ?? null;

        return is_array($credentials) ? $credentials : null;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function saveCredentials(
        string $providerKey,
        ?int $tenantId,
        array $credentials,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
        ?string $name = null,
    ): void {
        $json = json_encode($credentials);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode sender credentials to JSON');
        }

        $encrypted = $this->crypto->encrypt($json);
        $existing = $this->findSender($providerKey, $tenantId);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table}
                 SET credentials = :credentials, from_email = :from_email, from_name = :from_name,
                     reply_to = :reply_to, name = COALESCE(:name, name), updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
            );
            $stmt->execute([
                ':credentials' => $encrypted,
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':reply_to' => $replyTo,
                ':name' => $name,
                ':id' => $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, provider_key, name, credentials, from_email, from_name, reply_to, enabled, created_at, updated_at)
             VALUES (:tenant_id, :provider_key, :name, :credentials, :from_email, :from_name, :reply_to, TRUE,
                     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider_key' => $providerKey,
            ':name' => $name,
            ':credentials' => $encrypted,
            ':from_email' => $fromEmail,
            ':from_name' => $fromName,
            ':reply_to' => $replyTo,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?int $tenantId): array
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY provider_key");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSender(string $providerKey, ?int $tenantId): ?array
    {
        [$where, $params] = $this->tenantWhere('tenant_id', $tenantId);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->table} WHERE {$where} AND provider_key = :provider_key LIMIT 1",
        );
        $stmt->execute(array_merge($params, [':provider_key' => $providerKey]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decryptCredentials(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($this->crypto->decrypt($value), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Build a tenant-aware WHERE clause that works on MySQL and SQLite.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function tenantWhere(string $column, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return ["{$column} IS NULL", []];
        }

        return ["{$column} = :tenant_id", [':tenant_id' => $tenantId]];
    }
}
