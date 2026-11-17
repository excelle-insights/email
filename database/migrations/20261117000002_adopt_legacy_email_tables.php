<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds package columns to the existing Excelle email tables without replacing them.
 */
final class AdoptLegacyEmailTables extends AbstractMigration
{
    private string $prefix;

    public function up(): void
    {
        $this->prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'email_');

        if ($this->hasTable('queue')) {
            $this->adopt('queue', [
                'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
                'provider' => ['string', ['limit' => 64, 'null' => true]],
                'provider_message_id' => ['string', ['limit' => 255, 'null' => true]],
                'from_email' => ['string', ['limit' => 255, 'null' => true]],
                'from_name' => ['string', ['limit' => 255, 'null' => true]],
                'reply_to' => ['string', ['limit' => 255, 'null' => true]],
                'cc' => ['json', ['null' => true]],
                'bcc' => ['json', ['null' => true]],
                'attachments' => ['json', ['null' => true]],
                'text_content' => ['text', ['null' => true]],
                'template_id' => ['integer', ['null' => true, 'signed' => false]],
                'template_version' => ['integer', ['null' => true, 'signed' => false]],
                'template_variables' => ['json', ['null' => true]],
                'metadata' => ['json', ['null' => true]],
                'delivery_status' => ['string', ['limit' => 32, 'null' => false, 'default' => 'none']],
                'attempts' => ['integer', ['null' => false, 'default' => 0]],
                'idempotency_key' => ['string', ['limit' => 255, 'null' => true]],
                'origin' => ['string', ['limit' => 64, 'null' => false, 'default' => 'campaign']],
            ]);

            $this->copyTenantIds('queue');
            $this->addIndexes('queue', [
                ['tenant_id', 'status'],
                ['tenant_id', 'delivery_status'],
                ['idempotency_key'],
                ['provider_message_id'],
            ]);
        }

        if ($this->hasTable('campaigns')) {
            $this->adopt('campaigns', [
                'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
                'provider' => ['string', ['limit' => 64, 'null' => true]],
                'metadata' => ['json', ['null' => true]],
            ]);

            $this->copyTenantIds('campaigns');
            $this->addIndexes('campaigns', [['tenant_id']]);
        }

        if ($this->hasTable('templates')) {
            $this->adopt('templates', [
                'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
                'key' => ['string', ['limit' => 128, 'null' => true]],
                'category' => ['string', ['limit' => 32, 'null' => false, 'default' => 'marketing']],
                'status' => ['string', ['limit' => 32, 'null' => false, 'default' => 'draft']],
                'current_version' => ['integer', ['null' => false, 'default' => 1, 'signed' => false]],
            ]);

            $this->copyTenantIds('templates');
            $this->addIndexes('templates', [['tenant_id'], ['tenant_id', 'key']]);
        }
    }

    /**
     * Adopted tables and their legacy rows must survive a rollback.
     */
    public function down(): void
    {
    }

    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $columns
     */
    private function adopt(string $name, array $columns): void
    {
        $table = $this->table($name);
        $changed = false;

        foreach ($columns as $column => [$type, $options]) {
            if ($table->hasColumn($column)) {
                continue;
            }

            $table->addColumn($column, $type, $options);
            $changed = true;
        }

        if ($changed) {
            $table->update();
        }
    }

    private function copyTenantIds(string $name): void
    {
        $table = $this->table($name);
        if (! $table->hasColumn('workspace_id') || ! $table->hasColumn('tenant_id')) {
            return;
        }

        $this->execute(
            "UPDATE {$this->prefix}{$name} SET tenant_id = workspace_id "
            . 'WHERE tenant_id IS NULL AND workspace_id IS NOT NULL',
        );
    }

    /**
     * @param array<int, array<int, string>> $indexes
     */
    private function addIndexes(string $name, array $indexes): void
    {
        $table = $this->table($name);
        $changed = false;

        foreach ($indexes as $columns) {
            if ($table->hasIndex($columns)) {
                continue;
            }

            $table->addIndex($columns);
            $changed = true;
        }

        if ($changed) {
            $table->update();
        }
    }
}
