<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmailAuditTables extends AbstractMigration
{
    public function up(): void
    {
        $this->createStatusChangesTable();
        $this->createWebhookLogsTable();
        $this->createLogsTable();
        $this->ensureHttpRequestLogsTable();
    }

    public function down(): void
    {
        $this->table('logs')->drop()->save();
        $this->table('webhook_logs')->drop()->save();
        $this->table('status_changes')->drop()->save();
    }

    private function createStatusChangesTable(): void
    {
        $table = $this->table('status_changes', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('message_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('campaign_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('from_status', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('to_status', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('actor', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 32, 'null' => false, 'default' => 'application'])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['message_id'])
            ->addIndex(['campaign_id'])
            ->create();
    }

    private function createWebhookLogsTable(): void
    {
        $table = $this->table('webhook_logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('direction', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('headers', 'json', ['null' => true])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['provider', 'created_at'])
            ->create();
    }

    private function createLogsTable(): void
    {
        $table = $this->table('logs', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('level', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->create();
    }

    /**
     * The host app may already own this shared, non-prefixed table (agentpro-api does).
     * Add only the columns the package needs and never drop the table on rollback.
     */
    private function ensureHttpRequestLogsTable(): void
    {
        if (! $this->sharedTableExists('http_request_logs')) {
            $this->execute(
                'CREATE TABLE IF NOT EXISTS http_request_logs (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT UNSIGNED NULL,
                    provider VARCHAR(64) NULL,
                    level VARCHAR(10) NOT NULL DEFAULT \'INFO\',
                    message TEXT NULL,
                    method VARCHAR(16) NOT NULL,
                    url TEXT NOT NULL,
                    request_headers TEXT NULL,
                    request_body TEXT NULL,
                    response_status INT UNSIGNED NULL,
                    response_headers TEXT NULL,
                    response_body TEXT NULL,
                    error_message TEXT NULL,
                    duration_ms INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT NULL,
                    INDEX idx_http_method (method),
                    INDEX idx_http_status (response_status),
                    INDEX idx_http_provider_created (provider, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            );

            return;
        }

        $columns = [
            'tenant_id' => 'INT UNSIGNED NULL',
            'provider' => 'VARCHAR(64) NULL',
            'duration_ms' => 'INT UNSIGNED NULL',
        ];

        foreach ($columns as $column => $definition) {
            if ($this->sharedColumnExists('http_request_logs', $column)) {
                continue;
            }

            $this->execute("ALTER TABLE http_request_logs ADD COLUMN {$column} {$definition}");
        }
    }

    private function sharedTableExists(string $name): bool
    {
        $quotedName = (string) $this->getAdapter()->getConnection()->quote($name);
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS total FROM information_schema.tables '
            . "WHERE table_schema = DATABASE() AND table_name = {$quotedName}",
        );

        return $row !== false && (int) $row['total'] > 0;
    }

    private function sharedColumnExists(string $table, string $column): bool
    {
        $connection = $this->getAdapter()->getConnection();
        $quotedTable = (string) $connection->quote($table);
        $quotedColumn = (string) $connection->quote($column);
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS total FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = {$quotedTable}"
            . " AND column_name = {$quotedColumn}",
        );

        return $row !== false && (int) $row['total'] > 0;
    }
}
