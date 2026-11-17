<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates the M2 pipeline tables and adopts legacy Excelle email tables in place.
 */
final class CreateEmailPipelineTables extends AbstractMigration
{
    private string $prefix;

    public function up(): void
    {
        $this->prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'email_');

        $this->ensureQueueTable();
        $this->ensureCampaignsTable();
        $this->ensureTemplatesTable();
        $this->ensureTemplateVersionsTable();
        $this->ensureSuppressionsTable();
    }

    /**
     * Adopted tables and their legacy rows must survive a rollback.
     */
    public function down(): void
    {
    }

    private function ensureQueueTable(): void
    {
        $columns = [
            'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
            'campaign_id' => ['integer', ['null' => true, 'signed' => false]],
            'provider' => ['string', ['limit' => 64, 'null' => true]],
            'provider_message_id' => ['string', ['limit' => 255, 'null' => true]],
            'recipient_name' => ['string', ['limit' => 255, 'null' => true]],
            'recipient_email' => ['string', ['limit' => 255, 'null' => true]],
            'from_email' => ['string', ['limit' => 255, 'null' => true]],
            'from_name' => ['string', ['limit' => 255, 'null' => true]],
            'reply_to' => ['string', ['limit' => 255, 'null' => true]],
            'cc' => ['json', ['null' => true]],
            'bcc' => ['json', ['null' => true]],
            'subject' => ['text', ['null' => true]],
            'content_sections' => ['text', ['null' => true]],
            'text_content' => ['text', ['null' => true]],
            'template' => ['string', ['limit' => 128, 'null' => true]],
            'template_id' => ['integer', ['null' => true, 'signed' => false]],
            'template_version' => ['integer', ['null' => true, 'signed' => false]],
            'template_variables' => ['json', ['null' => true]],
            'variables' => ['text', ['null' => true]],
            'attachments' => ['json', ['null' => true]],
            'status' => ['string', ['limit' => 32, 'null' => true, 'default' => 'queued']],
            'delivery_status' => ['string', ['limit' => 32, 'null' => false, 'default' => 'none']],
            'send_date' => ['datetime', ['null' => true]],
            'attempts' => ['integer', ['null' => false, 'default' => 0]],
            'idempotency_key' => ['string', ['limit' => 255, 'null' => true]],
            'error_message' => ['text', ['null' => true]],
            'message_id' => ['string', ['limit' => 255, 'null' => true]],
            'origin' => ['string', ['limit' => 64, 'null' => false, 'default' => 'campaign']],
            'priority' => ['integer', ['null' => false, 'default' => 5]],
            'metadata' => ['json', ['null' => true]],
            'created_at' => ['datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP']],
            'updated_at' => ['datetime', ['null' => true]],
        ];

        $indexes = [
            ['tenant_id', 'status'],
            ['tenant_id', 'delivery_status'],
            ['campaign_id'],
            ['idempotency_key'],
            ['provider_message_id'],
            ['status', 'send_date'],
        ];

        $this->ensureTable('queue', $columns, $indexes);
        $this->copyTenantIds('queue');
    }

    private function ensureCampaignsTable(): void
    {
        $columns = [
            'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
            'name' => ['string', ['limit' => 255, 'null' => false]],
            'provider' => ['string', ['limit' => 64, 'null' => true]],
            'template_id' => ['integer', ['null' => true, 'signed' => false]],
            'subject' => ['string', ['limit' => 255, 'null' => true]],
            'total_recipients' => ['integer', ['null' => false, 'default' => 0]],
            'queued_count' => ['integer', ['null' => false, 'default' => 0]],
            'sent_count' => ['integer', ['null' => false, 'default' => 0]],
            'delivered_count' => ['integer', ['null' => false, 'default' => 0]],
            'failed_count' => ['integer', ['null' => false, 'default' => 0]],
            'opened_count' => ['integer', ['null' => false, 'default' => 0]],
            'clicked_count' => ['integer', ['null' => false, 'default' => 0]],
            'bounced_count' => ['integer', ['null' => false, 'default' => 0]],
            'status' => ['string', ['limit' => 50, 'null' => false, 'default' => 'draft']],
            'send_date' => ['datetime', ['null' => true]],
            'metadata' => ['json', ['null' => true]],
            'created_at' => ['datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP']],
            'updated_at' => ['datetime', ['null' => true]],
        ];

        $this->ensureTable('campaigns', $columns, [['tenant_id', 'status'], ['send_date']]);
        $this->copyTenantIds('campaigns');
    }

    private function ensureTemplatesTable(): void
    {
        $columns = [
            'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
            'key' => ['string', ['limit' => 128, 'null' => true]],
            'name' => ['string', ['limit' => 255, 'null' => false]],
            'subject' => ['string', ['limit' => 255, 'null' => true]],
            'category' => ['string', ['limit' => 32, 'null' => false, 'default' => 'marketing']],
            'status' => ['string', ['limit' => 32, 'null' => false, 'default' => 'draft']],
            'current_version' => ['integer', ['null' => false, 'default' => 1, 'signed' => false]],
            'created_at' => ['datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP']],
            'updated_at' => ['datetime', ['null' => true]],
        ];

        $this->ensureTable('templates', $columns, [['tenant_id', 'key'], ['status']]);
        $this->copyTenantIds('templates');
    }

    private function ensureTemplateVersionsTable(): void
    {
        if ($this->hasTable('template_versions')) {
            return;
        }

        $table = $this->table('template_versions', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('template_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('version', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('html', 'text', ['null' => false])
            ->addColumn('variables', 'json', ['null' => true])
            ->addColumn('author', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['template_id', 'version'], ['unique' => true])
            ->create();
    }

    private function ensureSuppressionsTable(): void
    {
        if ($this->hasTable('suppressions')) {
            return;
        }

        $table = $this->table('suppressions', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('type', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'email'])
            ->addIndex(['email', 'type'])
            ->create();
    }

    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $columns
     * @param array<int, array<int, string>> $indexes
     */
    private function ensureTable(string $name, array $columns, array $indexes): void
    {
        if (! $this->hasTable($name)) {
            $table = $this->table($name, ['id' => true, 'signed' => false]);
            foreach ($columns as $column => [$type, $options]) {
                $table->addColumn($column, $type, $options);
            }
            foreach ($indexes as $index) {
                $table->addIndex($index);
            }
            $table->create();

            return;
        }

        $table = $this->table($name);
        $changed = false;

        foreach ($columns as $column => [$type, $options]) {
            if ($table->hasColumn($column)) {
                continue;
            }

            $table->addColumn($column, $type, $options);
            $changed = true;
        }

        foreach ($indexes as $index) {
            if ($table->hasIndex($index)) {
                continue;
            }

            $table->addIndex($index);
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
}
